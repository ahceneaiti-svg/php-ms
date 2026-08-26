<?php

namespace App\Mail;

use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

final class WelcomeMailer
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly string $fromAddress,
    ) {
    }

    public function sendWelcomeEmail(string $toEmail, string $firstName): void
    {
        $email = (new Email())
            ->from($this->fromAddress)
            ->to($toEmail)
            ->subject('Bienvenue !')
            ->text(sprintf("Bonjour %s,\n\nBienvenue ! Votre compte a bien ete cree.\n", $firstName))
            ->html(sprintf(
                '<p>Bonjour %s,</p><p>Bienvenue ! Votre compte a bien ete cree.</p>',
                htmlspecialchars($firstName, ENT_QUOTES)
            ));

        $this->mailer->send($email);
    }
}
