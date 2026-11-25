---
title: Symfony Mailer compatibility
description: Send rendered application email through upstream Symfony Mailer and EvolvePHP public APIs.
sidebar_position: 4
---

# Symfony Mailer compatibility

Symfony Mailer sends an `Email` through an explicitly selected transport. Your application owns the transport configuration, sender, recipients, templates, and delivery policy. EvolvePHP does not register a mail service automatically. This guide uses upstream `symfony/mailer` and `symfony/mime`; there is no EvolvePHP mail package or Symfony FrameworkBundle requirement.

## Install in the application

Install `evolvephp/core`, `evolvephp/view-twig`, `symfony/mailer:^7.4`, and `symfony/mime:^7.4` in the consuming application. During a source preview, use a Composer path repository for the EvolvePHP packages as described in [source preview](../getting-started/source-preview.md). The isolated PHP 8.4.25 consumer for this guide resolved Mailer and Mime 7.4.22 and passed Composer's advisory check. Symfony 7.4 supports EvolvePHP's full PHP `^8.4` contract. Symfony 8.x Mailer and Mime require PHP 8.4.1 or newer, so applications supporting PHP 8.4.0 should stay on 7.4.

Symfony Mailer and Mime use the MIT license. Review the application's complete Composer lockfile and run `composer audit` when installing or updating packages. In particular, older Mime versions affected by the [address CRLF advisory](https://github.com/symfony/symfony/security/advisories/GHSA-qpmx-3rfj-7rhv) must not be used; the 7.4 fix began at 7.4.12. [Symfony's maintained versions](https://symfony.com/releases) and [Mailer documentation](https://symfony.com/doc/7.4/mailer.html) are the upstream sources for version and transport behavior.

## Register a transport and mailer

Read a DSN from application secret configuration. Leave the binding absent if mail is disabled. Register before calling `freeze()`. Keep the transport and mailer in an execution scope so a persistent worker does not retain an SMTP connection or message state across requests or jobs.

~~~php
<?php

use Evolve\Contracts\Execution\ResetParticipant;
use Evolve\Core\Container\ServiceLifetime;
use Evolve\Core\Container\ServiceRegistry;
use Evolve\Core\Execution\ExecutionScope;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\Smtp\SmtpTransport;
use Symfony\Component\Mailer\Transport\TransportInterface;

$services = new ServiceRegistry();
$dsn = getenv('MAILER_DSN');
if (is_string($dsn) && $dsn !== '') {
    $services->register(TransportInterface::class, ServiceLifetime::Execution,
        static function (ExecutionScope $scope) use ($dsn): TransportInterface {
            $transport = Transport::fromDsn($dsn);
            $scope->registerResetParticipant('mailer-transport', new class($transport) implements ResetParticipant {
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
}

$services->freeze();
~~~

Resolve `MailerInterface` from an active `ExecutionScope`, pass it to the application service through its constructor, and close the scope in `finally`. The root resolver deliberately cannot construct execution-lifetime services. For a test that cannot deliver mail, set `MAILER_DSN=null://null`; Symfony's null transport accepts the call and discards the message. Do not use it as evidence that a real provider accepted or delivered mail.

## Render and send an account notice

Put this template in an application-owned view root as `notice.html.twig`:

~~~twig
<p>Hello {{ name }},</p>
<p>Your account notice is ready.</p>
~~~

`TwigViewRenderer` escapes the supplied name in HTML. Keep a plain text body for readers and clients that do not display HTML. Construct recipient addresses through Symfony Mime's `Address`; reject invalid input before sending.

~~~php
<?php

use Evolve\View\ViewRenderer;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

final class AccountNoticeSender
{
    public function __construct(private MailerInterface $mailer, private ViewRenderer $views) {}

    public function send(string $recipient, string $name): void
    {
        $message = (new Email())
            ->from(new Address('notices@example.test'))
            ->to(new Address($recipient))
            ->subject('Account notice')
            ->text("Hello {$name},\nYour account notice is ready.")
            ->html($this->views->render('notice', ['name' => $name]));

        try {
            $this->mailer->send($message);
        } catch (TransportExceptionInterface) {
            throw new RuntimeException('Mail delivery failed.');
        }
    }
}
~~~

Create the renderer with `new TwigViewRenderer(new TwigViewPathResolver([new ViewSource(null, $absoluteViewDirectory)]))`, importing `Evolve\View\Twig\TwigViewRenderer`, `Evolve\View\Twig\TwigViewPathResolver`, and `Evolve\View\ViewSource`. The directory must be an existing absolute application path. Build `AccountNoticeSender` with the scope's `MailerInterface` and that renderer. Catch invalid recipient exceptions at the application boundary and return a generic validation error; do not print the rejected address, DSN, transport exception, credentials, or message body into logs or public responses.

An SMTP transport may keep a connection open after a send. The example's reset participant calls `stop()` when the scope closes. Close every scope even when rendering or sending fails. A successful `send()` means the transport accepted the message for further delivery; it does not prove inbox delivery. Configure real SMTP credentials, TLS, and provider delivery handling in the consuming application's deployment environment.

The [consumer fixture](https://github.com/josiahking/evolvephp/blob/2.x/tools/fixtures/symfony-mailer-consumer.php) and [validator](https://github.com/josiahking/evolvephp/blob/2.x/tools/validate-symfony-mailer-compatibility.php) exercise application-owned registration, two scopes, rendered HTML and text, invalid input, an unsupported DSN, a non-delivering null transport, and an unavailable loopback SMTP endpoint. The loopback check sends nothing externally. Provider authentication, TLS negotiation, and actual inbox delivery remain application integration checks.
