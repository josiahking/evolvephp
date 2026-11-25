<?php

declare(strict_types=1);

use Evolve\Core\Container\ServiceRegistry;
use Evolve\Core\Exception\ExecutionScopeUnavailable;
use Evolve\Core\Exception\ServiceResolutionFailed;
use Evolve\View\ViewSource;
use Evolve\View\Twig\TwigViewPathResolver;
use Evolve\View\Twig\TwigViewRenderer;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mailer\Transport\NullTransport;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

// Isolated consumer verification; EVOLVE_MAILER_CONSUMER_AUTOLOAD must point outside this repository.
$autoload = getenv('EVOLVE_MAILER_CONSUMER_AUTOLOAD');
if (!is_string($autoload) || !is_file($autoload)) {
    fwrite(STDERR, "Set EVOLVE_MAILER_CONSUMER_AUTOLOAD to an isolated consumer autoload.php.\n");
    exit(2);
}

require $autoload;
require __DIR__ . '/fixtures/symfony-mailer-consumer.php';

function assertMailer(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function expectMailerFailure(callable $operation, string $expected, string $message): Throwable
{
    try {
        $operation();
    } catch (Throwable $error) {
        if (!$error instanceof $expected) {
            throw new RuntimeException($message . ' Unexpected category: ' . $error::class);
        }
        return $error;
    }
    throw new RuntimeException($message);
}

try {
    $disabled = new ServiceRegistry();
    assertMailer(!registerSymfonyMailer($disabled, null), 'Missing DSN registered mail services.');
    assertMailer(!$disabled->freeze()->has(MailerInterface::class), 'Missing DSN exposed a mailer.');

    $invalid = new ServiceRegistry();
    assertMailer(registerSymfonyMailer($invalid, 'unrecognized://user:secret@example.test'), 'Malformed DSN was not registered for resolution.');
    $invalid->freeze();
    $invalidScope = $invalid->createExecutionScope();
    try {
        $failure = expectMailerFailure(
            static fn() => $invalidScope->get(MailerInterface::class),
            ServiceResolutionFailed::class,
            'Unsupported DSN resolved.',
        );
        assertMailer($failure->getPrevious() instanceof InvalidArgumentException, 'DSN failure lost its safe category.');
        assertMailer($failure->getPrevious()->getMessage() === 'Mailer transport configuration failed.', 'DSN error was not sanitized.');
        assertMailer(!str_contains($failure->getMessage(), 'secret'), 'DSN credential leaked.');
    } finally {
        $invalidScope->close();
    }

    $renderer = new TwigViewRenderer(new TwigViewPathResolver([
        new ViewSource(null, __DIR__ . '/fixtures/symfony-mailer-views'),
    ]));
    $unsafeName = '<script>alert(1)</script>';
    $message = createNoticeEmail($renderer, 'reader@example.test', $unsafeName);
    assertMailer($message->getTo()[0]->getAddress() === 'reader@example.test', 'Recipient was not preserved.');
    assertMailer(str_contains((string) $message->getHtmlBody(), '&lt;script&gt;'), 'HTML body did not escape user data.');
    assertMailer(!str_contains((string) $message->getHtmlBody(), '<script>'), 'HTML body retained executable markup.');
    assertMailer(str_contains((string) $message->getTextBody(), $unsafeName), 'Text body lost its input.');
    assertMailer(str_contains((string) $message->getHtmlBody(), 'account notice'), 'Rendered application notice was incomplete.');
    $recipientFailure = expectMailerFailure(
        static fn() => createNoticeEmail($renderer, "bad\r\nBcc: attacker@example.test", 'reader'),
        InvalidArgumentException::class,
        'Header injection recipient was accepted.',
    );
    assertMailer($recipientFailure->getMessage() === 'Invalid mail recipient.', 'Recipient failure exposed its input.');

    $services = new ServiceRegistry();
    assertMailer(registerSymfonyMailer($services, 'null://null'), 'Test transport was not registered.');
    $root = $services->freeze();
    assertMailer($root->has(MailerInterface::class), 'Mailer binding is absent.');
    expectMailerFailure(static fn() => $root->get(MailerInterface::class), ExecutionScopeUnavailable::class, 'Root resolved an execution mailer.');
    $first = $services->createExecutionScope();
    $second = $services->createExecutionScope();
    try {
        $firstMailer = $first->get(MailerInterface::class);
        $secondMailer = $second->get(MailerInterface::class);
        assertMailer($firstMailer === $first->get(MailerInterface::class), 'Mailer changed within one scope.');
        assertMailer($firstMailer !== $secondMailer, 'Mailer leaked across scopes.');
        assertMailer($first->get(TransportInterface::class) instanceof NullTransport, 'Test mode can deliver mail.');
        assertMailer($first->get(TransportInterface::class) !== $second->get(TransportInterface::class), 'Transport leaked across scopes.');
        sendNotice($firstMailer, $message);
        sendNotice($secondMailer, $message);
    } finally {
        $first->close();
        $second->close();
    }

    $missingRecipient = (new Email())->from(new Address('notices@example.test'))->text('body');
    $third = $services->createExecutionScope();
    try {
        expectMailerFailure(static fn() => sendNotice($third->get(MailerInterface::class), $missingRecipient), LogicException::class, 'Missing recipient was sent.');
    } finally {
        $third->close();
    }

    $failing = new class implements MailerInterface {
        public function send(\Symfony\Component\Mime\RawMessage $message, ?\Symfony\Component\Mailer\Envelope $envelope = null): void
        {
            throw new TransportException('secret credential and private body');
        }
    };
    $deliveryFailure = expectMailerFailure(static fn() => sendNotice($failing, $message), RuntimeException::class, 'Transport failure was accepted.');
    assertMailer($deliveryFailure->getMessage() === 'Mail delivery failed.', 'Transport failure leaked sensitive details.');

    // Port 1 on loopback is deliberately unavailable; no external delivery is attempted.
    $unavailable = new ServiceRegistry();
    registerSymfonyMailer($unavailable, 'smtp://user:secret@127.0.0.1:1');
    $unavailable->freeze();
    $smtpScope = $unavailable->createExecutionScope();
    try {
        $smtpFailure = expectMailerFailure(
            static fn() => sendNotice($smtpScope->get(MailerInterface::class), $message),
            RuntimeException::class,
            'Unavailable loopback SMTP accepted the message.',
        );
        assertMailer($smtpFailure->getMessage() === 'Mail delivery failed.', 'SMTP failure exposed transport details.');
    } finally {
        $smtpScope->close();
    }

    echo "PASS isolated Symfony Mailer consumer: scoped services, rendered HTML/text, validation, redaction, null and unavailable loopback SMTP transports\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL ' . $error::class . ': ' . $error->getMessage() . "\n");
    exit(1);
}
