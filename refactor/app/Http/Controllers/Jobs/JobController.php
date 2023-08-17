<?php

namespace DTApi\Http\Controllers\Jobs;

use DTApi\Models\Job;
use DTApi\Http\Requests;
use Illuminate\Http\Request;
use DTApi\Repository\BookingRepository;

class JobController extends Controller
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
    public function acceptJob(Request $request)
    {
        $data = $request->all();
        $user = $request->user();

        $response = $this->repository->acceptJob($data, $user);

        return response($response);
    }

    /**
     * @param Request $request
     * @return \Illuminate\Contracts\Routing\ResponseFactory|\Symfony\Component\HttpFoundation\Response
     */
    public function acceptJobWithId(Request $request)
    {
        $job_id = $request->get('job_id');
        $user = $request->user();

        $response = $this->repository->acceptJobWithId($job_id, $user);

        return response($response);
    }

    /**
     * @param Request $request
     * @return \Illuminate\Contracts\Routing\ResponseFactory|\Symfony\Component\HttpFoundation\Response
     */
    public function cancelJob(Request $request)
    {
        $data = $request->all();
        $user = $request->user();

        $response = $this->repository->cancelJobAjax($data, $user);

        return response($response);
    }

    /**
     * @param Request $request
     * @return \Illuminate\Contracts\Routing\ResponseFactory|\Symfony\Component\HttpFoundation\Response
     */
    public function endJob(Request $request)
    {
        $response = $this->repository->endJob($request->all());

        return response($response);
    }

    /**
     * @param Request $request
     * @return \Illuminate\Contracts\Routing\ResponseFactory|\Symfony\Component\HttpFoundation\Response
     */
    public function customerNotCall(Request $request)
    {
        $response = $this->repository->customerNotCall($request->all());

        return response($response);
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function getPotentialJobs(Request $request)
    {
        $response = $this->repository->getPotentialJobs($request->user());

        return response($response);
    }
}
