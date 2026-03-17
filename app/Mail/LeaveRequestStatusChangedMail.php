<?php

namespace App\Mail;

use App\Models\LeaveRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class LeaveRequestStatusChangedMail extends Mailable
{
    use Queueable, SerializesModels;

    public $leaveRequest;
    public $employeeName;

    /**
     * Create a new message instance.
     */
    public function __construct(LeaveRequest $leaveRequest, $employeeName)
    {
        $this->leaveRequest = $leaveRequest;
        $this->employeeName = $employeeName;
    }

    /**
     * Build the message.
     */
    public function build()
    {
        $subject = $this->leaveRequest->status === LeaveRequest::STATUS_APPROVED 
            ? 'Permohonan Cuti Disetujui' 
            : 'Permohonan Cuti Ditolak';

        return $this->subject($subject)
                    ->view('emails.leave-request-status-changed')
                    ->with([
                        'employeeName' => $this->employeeName,
                        'requestType' => $this->leaveRequest->request_type_name,
                        'startDate' => $this->leaveRequest->start_date->format('d M Y'),
                        'endDate' => $this->leaveRequest->end_date->format('d M Y'),
                        'totalDays' => $this->leaveRequest->total_days,
                        'status' => $this->leaveRequest->status,
                        'statusName' => $this->leaveRequest->status_name,
                        'approvalNotes' => $this->leaveRequest->approval_notes,
                        'approvedBy' => $this->leaveRequest->approvedByManager?->user->name,
                        'approvedAt' => $this->leaveRequest->approved_at?->format('d M Y, H:i'),
                        'leaveRequestId' => $this->leaveRequest->id,
                    ]);
    }
}
