<?php

declare(strict_types=1);

use Evolve\Contracts\Execution\ResetParticipant;
use Evolve\Core\Container\ServiceLifetime;
use Evolve\Core\Container\ServiceRegistry;
use Evolve\Core\Execution\ExecutionScope;
use Evolve\View\ViewRenderer;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Exception\InvalidArgumentException as MailerConfigurationError;
use Symfony\Component\Mailer\Exception\UnsupportedSchemeException;
use Symfony\Component\Mailer\Transport\Smtp\SmtpTransport;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Exception\InvalidArgumentException as InvalidAddress;
use Symfony\Component\Mime\Exception\RfcComplianceException;

/** Application-owned registration. A missing DSN leaves mail unavailable. */
function registerSymfonyMailer(ServiceRegistry $services, ?string $dsn): bool
{
    if ($dsn === null || $dsn === '') {
        return false;
    }

    $services->register(TransportInterface::class, ServiceLifetime::Execution,
        static function (ExecutionScope $scope) use ($dsn): TransportInterface {
            try {
                $transport = Transport::fromDsn($dsn);
            } catch (MailerConfigurationError|UnsupportedSchemeException) {
                throw new InvalidArgumentException('Mailer transport configuration failed.');
            }
            $scope->registerResetParticipant('symfony-mailer-transport', new class($transport) implements ResetParticipant {
                public function __construct(private TransportInterface $transport) {}

                public function reset(): void
                {
                    if ($this->transport instanceof SmtpTransport) {
                        $this->transport->stop();
                    }
                }
            });
            return $transport;
        });

    $services->register(MailerInterface::class, ServiceLifetime::Execution,
        static fn(ExecutionScope $scope): MailerInterface => new Mailer($scope->get(TransportInterface::class)));

    return true;
}

/** Build one message with an application-owned Twig view and explicit recipient validation. */
function createNoticeEmail(ViewRenderer $renderer, string $recipient, string $name): Email
{
    try {
        $address = new Address($recipient);
    } catch (InvalidAddress|RfcComplianceException) {
        throw new InvalidArgumentException('Invalid mail recipient.');
    }

    return (new Email())
        ->from(new Address('notices@example.test'))
        ->to($address)
        ->subject('Account notice')
        ->text("Hello {$name},\nYour account notice is ready.")
        ->html($renderer->render('notice', ['name' => $name]));
}

/** Keep transport diagnostics, credentials and message bodies out of caller-visible errors. */
function sendNotice(MailerInterface $mailer, Email $message): void
{
    try {
        $mailer->send($message);
    } catch (TransportExceptionInterface) {
        throw new RuntimeException('Mail delivery failed.');
    }
}
