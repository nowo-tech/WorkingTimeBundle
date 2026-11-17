<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Tests\Unit\Infrastructure\Symfony;

use Nowo\WorkingTimeBundle\Application\Register\Port\ExportGeneratorInterface;
use Nowo\WorkingTimeBundle\Domain\Port\WorkerDirectoryInterface;
use Nowo\WorkingTimeBundle\Domain\Shared\NoticeMessage;
use Nowo\WorkingTimeBundle\Domain\Shared\TenantRef;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkerProfile;
use Nowo\WorkingTimeBundle\Domain\Shared\WorkerRef;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Messenger\GenerateRegisterExportHandler;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Messenger\GenerateRegisterExportMessage;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Messenger\MessengerExportDispatcher;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Port\MailerNotifier;
use Nowo\WorkingTimeBundle\Infrastructure\Symfony\Port\NeutralBrandProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Translation\IdentityTranslator;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * The default Mailer notifier (one branded e-mail in the recipient's locale, nothing without an address) and the
 * Messenger export dispatcher / handler.
 */
final class MailerAndMessengerTest extends TestCase
{
    public function testANoticeBecomesOneEmailToTheWorker(): void
    {
        $sent   = [];
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->method('send')->willReturnCallback(static function (Email $email) use (&$sent): void {
            $sent[] = $email;
        });
        $loader = new FilesystemLoader();
        $loader->addPath(__DIR__ . '/../../../../src/Resources/views', 'NowoWorkingTime');
        $directory = $this->createStub(WorkerDirectoryInterface::class);
        $directory->method('worker')->willReturnCallback(static fn (TenantRef $tenant, WorkerRef $worker): ?WorkerProfile => match ($worker->value) {
            'ana'   => new WorkerProfile($worker, 'Ana', 'ana@example.test', 'es'),
            'bob'   => new WorkerProfile($worker, 'Bob'),
            default => null,
        });
        $notifier = new MailerNotifier($mailer, $directory, new NeutralBrandProvider('Acme'), new IdentityTranslator(), new Environment($loader), 'hr@example.test');

        $notifier->send(new NoticeMessage(new TenantRef('t'), new WorkerRef('ana'), 'register.monthly_summary', ['month' => '2026-10'], '/working-time/month/2026-10', 'es'));
        $notifier->send(new NoticeMessage(new TenantRef('t'), new WorkerRef('bob'), 'register.reminder_in'));
        $notifier->send(new NoticeMessage(new TenantRef('t'), new WorkerRef('ghost'), 'register.reminder_in'));

        self::assertCount(1, $sent);
        self::assertSame('ana@example.test', $sent[0]->getTo()[0]->getAddress());
        self::assertSame('notice.register_monthly_summary.subject', $sent[0]->getSubject());
        self::assertStringContainsString('/working-time/month/2026-10', (string) $sent[0]->getHtmlBody());
    }

    public function testExportsGoThroughMessenger(): void
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())->method('dispatch')->with(self::callback(static fn (object $m): bool => $m instanceof GenerateRegisterExportMessage && $m->exportId === 'e1'))->willReturnCallback(static fn (object $m): Envelope => new Envelope($m));
        (new MessengerExportDispatcher($bus))->dispatch('e1');

        $generator = $this->createMock(ExportGeneratorInterface::class);
        $generator->expects(self::once())->method('generate')->with('e2')->willReturn(true);
        (new GenerateRegisterExportHandler($generator))(new GenerateRegisterExportMessage('e2'));
    }
}
