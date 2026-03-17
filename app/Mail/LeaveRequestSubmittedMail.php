<?php

namespace App\Mail;

use App\Models\LeaveRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class LeaveRequestSubmittedMail extends Mailable
{
    use Queueable, SerializesModels;

    public $leaveRequest;
    public $managerName;

    /**
     * Create a new message instance.
     */
    public function __construct(LeaveRequest $leaveRequest, $managerName)
    {
        $this->leaveRequest = $leaveRequest;
        $this->managerName = $managerName;
    }

    /**
     * Build the message.
     */
    public function build()
    {
        return $this->subject('Permohonan Cuti Baru - ' . $this->leaveRequest->employee->user->name)
                    ->view('emails.leave-request-submitted')
                    ->with([
                        'employeeName' => $this->leaveRequest->employee->user->name,
                        'managerName' => $this->managerName,
                        'requestType' => $this->leaveRequest->request_type_name,
                        'startDate' => $this->leaveRequest->start_date->format('d M Y'),
                        'endDate' => $this->leaveRequest->end_date->format('d M Y'),
                        'totalDays' => $this->leaveRequest->total_days,
                        'reason' => $this->leaveRequest->reason,
                        'institution' => $this->leaveRequest->institution,
                        'educationType' => $this->leaveRequest->education_type,
                        'programCourse' => $this->leaveRequest->program_course,
                        'createdAt' => $this->leaveRequest->created_at->format('d M Y, H:i'),
                        'leaveRequestId' => $this->leaveRequest->id,
                    ]);
    }
}
