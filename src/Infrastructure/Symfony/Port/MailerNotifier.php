<?php

declare(strict_types=1);

namespace Nowo\WorkingTimeBundle\Infrastructure\Symfony\Port;

use Nowo\Brand\Contract\BrandProviderInterface;
use Nowo\WorkingTimeBundle\Domain\Port\WorkerDirectoryInterface;
use Nowo\WorkingTimeBundle\Domain\Port\WorkingTimeNotifierInterface;
use Nowo\WorkingTimeBundle\Domain\Shared\NoticeMessage;
use Nowo\WorkingTimeBundle\NowoWorkingTimeBundle;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

use function str_replace;

/**
 * Default notifier when Symfony Mailer is installed (contract section 8.2): one branded e-mail in the recipient's
 * locale (`@NowoWorkingTime/email/notice.html.twig`). A recipient without an e-mail address gets nothing.
 */
final readonly class MailerNotifier implements WorkingTimeNotifierInterface
{
    /**
     * Wires the collaborators.
     */
    public function __construct(
        private MailerInterface $mailer,
        private WorkerDirectoryInterface $directory,
        private BrandProviderInterface $brand,
        private TranslatorInterface $translator,
        private Environment $twig,
        private string $from = 'no-reply@localhost',
    ) {
    }

    /**
     * Sends the e-mail.
     */
    public function send(NoticeMessage $message): void
    {
        $profile = $this->directory->worker($message->tenant, $message->recipient);
        if ($profile === null || $profile->email === null || $profile->email === '') {
            return;
        }
        $key    = 'notice.' . str_replace('.', '_', $message->kind);
        $params = [];
        foreach ($message->params as $name => $value) {
            $params['%' . $name . '%'] = (string) $value;
        }
        $subject = $this->translator->trans($key . '.subject', $params, NowoWorkingTimeBundle::TRANSLATION_DOMAIN, $message->locale);
        $email   = (new Email())
            ->from(new Address($this->from, $this->brand->getBrand()->name))
            ->to(new Address($profile->email, $profile->displayName))
            ->subject($subject)
            ->html($this->twig->render('@NowoWorkingTime/email/notice.html.twig', [
                'brand'   => $this->brand->getBrand(),
                'subject' => $subject,
                'body'    => $this->translator->trans($key . '.body', $params, NowoWorkingTimeBundle::TRANSLATION_DOMAIN, $message->locale),
                'action'  => $message->actionPath,
                'locale'  => $message->locale,
            ]));
        $this->mailer->send($email);
    }
}
