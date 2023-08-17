<?php

namespace DTApi\Http\Controllers\Jobs;

use DTApi\Models\Job;
use DTApi\Http\Requests;
use Illuminate\Http\Request;
use DTApi\Repository\BookingRepository;

class DistanceFeedController extends Controller
{

    /**
     * @var BookingRepository
     */
    protected $repository;

    /**
     * BookingController constructor.
     * @param BookingRepository $bookingRepository
     */
    public function __construct(BookingRepository $bookingRepository)
    {
        $this->repository = $bookingRepository;
    }

    /**
     * @param Request $request
     * @return \Illuminate\Contracts\Routing\ResponseFactory|\Symfony\Component\HttpFoundation\Response
     */
    public function distanceFeed(Request $request)
    {
        $data = $request->all();
        $jobid = $data['jobid'];

        $distance = $data['distance'] ?? '';
        $time = $data['time'] ?? '';
        $session = $data['session_time'] ?? '';
        $admincomment = $data['admincomment'] ?? '';

        $flagged = ($data['flagged'] == 'true' && $data['admincomment'] != '') ? 'yes' : 'no';
        $manually_handled = ($data['manually_handled'] == 'true') ? 'yes' : 'no';
        $by_admin = ($data['by_admin'] == 'true') ? 'yes' : 'no';

        if ($distance || $time) {
            Distance::query()
                ->whereJobId($jobid)
                ->update([
                    'distance' => $distance,
                    'time' => $time
                ]);
        }

        if ($admincomment || $session || $flagged || $manually_handled || $by_admin) {
            Job::query()
                ->where('id', '=', $jobid)
                ->update([
                    'admin_comments' => $admincomment,
                    'flagged' => $flagged,
                    'session_time' => $session,
                    'manually_handled' => $manually_handled,
                    'by_admin' => $by_admin
                ]);
        }

        return response('Record updated!');
    }


    /**
     * @param Request $request
     * @return \Illuminate\Contracts\Routing\ResponseFactory|\Symfony\Component\HttpFoundation\Response
     */
    public function getHistory(Request $request)
    {
        $user_id = $request->get('user_id');

        if (!$user_id) {
            return null;
        }

        $response = $this->repository->getUsersJobsHistory($user_id, $request);

        return response($response);
    }
}
