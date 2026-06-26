<?php

namespace App\Mail;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\RawMessage;

final class MicrosoftGraphTransport extends AbstractTransport
{
    public function __construct(
        private readonly string $tenantId,
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $sender,
    ) {
        parent::__construct();
    }

    public function __toString(): string
    {
        return 'microsoft365';
    }

    protected function doSend(SentMessage $message): void
    {
        $email = $message->getOriginalMessage();
        if (! $email instanceof Email) {
            throw new RuntimeException('Microsoft 365 mail transport only supports Symfony Email messages.');
        }

        $response = Http::withToken($this->accessToken())
            ->acceptJson()
            ->asJson()
            ->post('https://graph.microsoft.com/v1.0/users/'.rawurlencode($this->sender).'/sendMail', [
                'message' => $this->graphMessage($email),
                'saveToSentItems' => true,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('Microsoft 365 mail failed: '.$response->status().' '.$response->body());
        }
    }

    protected function doSendRawMessage(RawMessage $message, ?Envelope $envelope = null): void
    {
        throw new RuntimeException('Microsoft 365 mail transport does not support raw MIME messages.');
    }

    /**
     * @return array<string, mixed>
     */
    private function graphMessage(Email $email): array
    {
        $html = $email->getHtmlBody();
        $text = $email->getTextBody();
        $body = $html ?? $text ?? '';
        $payload = [
            'subject' => $email->getSubject() ?? '',
            'body' => [
                'contentType' => $html !== null ? 'HTML' : 'Text',
                'content' => $body,
            ],
            'toRecipients' => $this->recipients($email->getTo()),
        ];

        if ($email->getCc() !== []) {
            $payload['ccRecipients'] = $this->recipients($email->getCc());
        }

        if ($email->getBcc() !== []) {
            $payload['bccRecipients'] = $this->recipients($email->getBcc());
        }

        $attachments = $this->attachments($email);
        if ($attachments !== []) {
            $payload['attachments'] = $attachments;
        }

        return $payload;
    }

    /**
     * @param array<int, Address> $addresses
     * @return array<int, array{emailAddress: array{address: string, name?: string}}>
     */
    private function recipients(array $addresses): array
    {
        return array_map(
            fn (Address $address): array => [
                'emailAddress' => array_filter([
                    'address' => $address->getAddress(),
                    'name' => $address->getName(),
                ], fn (?string $value): bool => $value !== null && $value !== ''),
            ],
            $addresses,
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function attachments(Email $email): array
    {
        return array_values(array_map(
            fn (DataPart $part): array => [
                '@odata.type' => '#microsoft.graph.fileAttachment',
                'name' => $part->getFilename() ?? 'attachment',
                'contentType' => trim($part->getMediaType().'/'.$part->getMediaSubtype(), '/'),
                'contentBytes' => base64_encode($part->bodyToString()),
            ],
            $email->getAttachments(),
        ));
    }

    private function accessToken(): string
    {
        if ($this->tenantId === '' || $this->clientId === '' || $this->clientSecret === '' || $this->sender === '') {
            throw new RuntimeException('Microsoft 365 mail is not fully configured.');
        }

        $cacheKey = 'microsoft365-mail-token:'.sha1($this->tenantId.'|'.$this->clientId);

        return Cache::remember($cacheKey, now()->addMinutes(50), function (): string {
            $response = Http::asForm()
                ->acceptJson()
                ->post('https://login.microsoftonline.com/'.rawurlencode($this->tenantId).'/oauth2/v2.0/token', [
                    'grant_type' => 'client_credentials',
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                    'scope' => 'https://graph.microsoft.com/.default',
                ]);

            if (! $response->successful()) {
                throw new RuntimeException('Microsoft 365 token request failed: '.$response->status().' '.$response->body());
            }

            $token = $response->json('access_token');
            if (! is_string($token) || $token === '') {
                throw new RuntimeException('Microsoft 365 token response did not contain an access token.');
            }

            return $token;
        });
    }
}
