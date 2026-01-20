<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ActivationCodeEmail extends Mailable
{
    use Queueable, SerializesModels;

    public $token;
    public $userName;
    public $purpose; // 'activation' or 'reset_password'
    public $expiredAt;

    /**
     * Create a new message instance.
     *
     * @return void
     */
    public function __construct(string $token, string $userName, string $purpose, string $expiredAt)
    {
        $this->token = $token;
        $this->userName = $userName;
        $this->purpose = $purpose;
        $this->expiredAt = $expiredAt;
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        $subject = $this->purpose === 'reset_password' 
            ? 'Password Reset Code - AIRNAV System'
            : 'Account Activation Code - AIRNAV System';

        return $this->subject($subject)
                    ->view('emails.activation-code')
                    ->with([
                        'token' => $this->token,
                        'userName' => $this->userName,
                        'purpose' => $this->purpose,
                        'expiredAt' => $this->expiredAt,
                        'appUrl' => env('APP_FRONTEND_URL', 'http://localhost:5173'),
                    ]);
    }
}
