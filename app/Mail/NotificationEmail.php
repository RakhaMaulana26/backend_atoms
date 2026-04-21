<?php

namespace App\Mail;

use App\Models\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class NotificationEmail extends Mailable
{
    use Queueable, SerializesModels;

    public $notification;
    public $userName;

    /**
     * Create a new message instance.
     */
    public function __construct(Notification $notification, $userName)
    {
        $this->notification = $notification;
        $this->userName = $userName;
    }

    /**
     * Build the message.
     */
    public function build()
    {
        return $this->subject($this->notification->title)
                    ->view('emails.notification')
                    ->with([
                        'title' => $this->notification->title,
                        'bodyMessage' => $this->notification->message,
                        'userName' => $this->userName,
                        'createdAt' => $this->notification->created_at->format('d M Y, H:i'),
                    ]);
    }
}
