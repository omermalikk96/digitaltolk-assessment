<?php

namespace DTApi\Http\Controllers\Notifications;

use DTApi\Http\Controllers\Controller;
use Illuminate\Http\Request;
use DTApi\Repository\BookingRepository;

class NotificationController extends Controller
{
    protected $repository;

    public function __construct(BookingRepository $bookingRepository)
    {
        $this->repository = $bookingRepository;
    }

    public function resendNotifications(Request $request)
    {
        try {
            $job = $this->repository->find($request->get('jobid'));

            $jobData = $this->repository->jobToData($job);

            $this->repository->sendNotificationTranslator($job, $jobData, '*');

            return response(['success' => 'Push sent']);
        } catch (Exception $e) {
            return response(['error' => $e->getMessage()]);
        }
    }

    /**
     * Sends SMS to Translator
     * @param Request $request
     * @return \Illuminate\Contracts\Routing\ResponseFactory|\Symfony\Component\HttpFoundation\Response
     */
    public function resendSMSNotifications(Request $request)
    {
        try {
            $job = $this->repository->find($request->get('jobid'));
            $this->repository->sendSMSNotificationToTranslator($job);

            return response(['success' => 'SMS sent']);
        } catch (\Exception $e) {
            return response(['error' => $e->getMessage()]);
        }
    }
}
