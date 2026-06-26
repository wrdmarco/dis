<?php

namespace App\Mail;

use App\Models\UserCertification;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

final class CertificationExpiryMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly UserCertification $userCertification,
        public readonly int $daysUntilExpiry,
        public readonly string $downloadUrl,
    ) {}

    public function build(): self
    {
        $certificationName = $this->userCertification->certification?->name ?? 'Certificaat';
        $subject = $this->daysUntilExpiry < 0
            ? "$certificationName is verlopen"
            : "$certificationName verloopt over {$this->daysUntilExpiry} dagen";

        return $this
            ->subject($subject)
            ->view('mail.certification-expiry');
    }
}
