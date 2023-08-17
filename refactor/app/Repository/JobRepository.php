<?php

namespace DTApi\Repository;

use App\Traits\NotificationTrait;
use DTApi\Events\SessionEnded;
use DTApi\Helpers\SendSMSHelper;
use Event;
use Carbon\Carbon;
use Monolog\Logger;
use DTApi\Models\Job;
use DTApi\Models\User;
use DTApi\Models\Language;
use DTApi\Models\UserMeta;
use DTApi\Helpers\TeHelper;
use Illuminate\Http\Request;
use DTApi\Models\Translator;
use DTApi\Mailers\AppMailer;
use DTApi\Models\UserLanguages;
use DTApi\Events\JobWasCreated;
use DTApi\Events\JobWasCanceled;

/**
 * Class JobRepository
 * @package DTApi\Repository
 */
class JobRepository extends BaseRepository
{
    use NotificationTrait;
    protected $model;
    protected $mailer;
    protected $logger;

    /**
     * @param Job $model
     */
    function __construct(Job $model)
    {
        parent::__construct($model);
        $this->model = $model;
    }

    /**
     * @param $user_id
     * @return array
     */
    public function getUsersJobs($user_id)
    {
        $user = User::find($user_id);

        if (!$user) {
            return ['emergencyJobs' => [], 'normalJobs' => [], 'user' => null, 'usertype' => ''];
        }

        $usertype = $user->is('customer') ? 'customer' : ($user->is('translator') ? 'translator' : '');

        $emergencyJobs = [];
        $normalJobs = [];

        if ($usertype === 'customer') {
            $jobs = $user->jobs()
                ->with('user.userMeta', 'user.average', 'translatorJobRel.user.average', 'language', 'feedback')
                ->whereIn('status', ['pending', 'assigned', 'started'])
                ->orderBy('due', 'asc')
                ->get();
        } elseif ($usertype === 'translator') {
            $jobs = $this->model->getTranslatorJobs($user->id, 'new')->pluck('jobs')->all();
        }

        if (!empty($jobs)) {
            foreach ($jobs as $jobitem) {
                if ($jobitem->immediate === 'yes') {
                    $emergencyJobs[] = $jobitem;
                } else {
                    $normalJobs[] = $jobitem;
                }
            }

            $normalJobs = collect($normalJobs)->map(function ($item) use ($user_id) {
                $item['usercheck'] = $this->model->checkParticularJob($user_id, $item);
                return $item;
            })->sortBy('due')->all();
        }

        return [
            'emergencyJobs' => $emergencyJobs,
            'normalJobs' => $normalJobs,
            'cuser' => $user,
            'usertype' => $usertype
        ];
    }



    /**
     * @param $user_id
     * @return array
     */
    public function getUsersJobsHistory($user_id, Request $request)
    {
        $page = $request->get('page');
        $pagenum = isset($page) ? $page : 1;

        $user = User::find($user_id);

        if (!$user) {
            return ['emergencyJobs' => [], 'noramlJobs' => [], 'jobs' => null, 'cuser' => null, 'usertype' => '', 'numpages' => 0, 'pagenum' => 0];
        }

        $usertype = $user->is('customer') ? 'customer' : ($user->is('translator') ? 'translator' : '');

        $emergencyJobs = [];

        if ($usertype === 'customer') {
            $jobs = $user->jobs()
                ->with('user.userMeta', 'user.average', 'translatorJobRel.user.average', 'language', 'feedback', 'distance')
                ->whereIn('status', ['completed', 'withdrawbefore24', 'withdrawafter24', 'timedout'])
                ->orderBy('due', 'desc')
                ->paginate(15);

            return [
                'emergencyJobs' => $emergencyJobs,
                'noramlJobs' => [],
                'jobs' => $jobs,
                'cuser' => $user,
                'usertype' => $usertype,
                'numpages' => 0,
                'pagenum' => 0
            ];
        } elseif ($usertype === 'translator') {
            $jobs_ids = $this->model->getTranslatorJobsHistoric($user->id, 'historic', $pagenum);
            $totaljobs = $jobs_ids->total();
            $numpages = ceil($totaljobs / 15);

            $normalJobs = $jobs_ids;

            return [
                'emergencyJobs' => $emergencyJobs,
                'noramlJobs' => $normalJobs,
                'jobs' => $jobs_ids,
                'cuser' => $user,
                'usertype' => $usertype,
                'numpages' => $numpages,
                'pagenum' => $pagenum
            ];
        }
    }

    /**
     * @param $data
     * @return mixed
     */
    public function storeJobEmail($data)
    {
        $user_type = $data['user_type'];
        $job = $this->model->findOrFail($data['user_email_job_id']);
        $job->user_email = $data['user_email'] ?? '';
        $job->reference = $data['reference'] ?? '';
        $user = $job->user()->first();

        if (isset($data['address'])) {
            $job->address = $data['address'] ?: $user->userMeta->address;
            $job->instructions = $data['instructions'] ?: $user->userMeta->instructions;
            $job->town = $data['town'] ?: $user->userMeta->city;
        }

        $job->save();

        $email = !empty($job->user_email) ? $job->user_email : $user->email;
        $name = $user->name;
        $subject = 'Vi har mottagit er tolkbokning. Bokningsnr: #' . $job->id;
        $send_data = [
            'user' => $user,
            'job' => $job
        ];
        $this->mailer->send($email, $name, $subject, 'emails.job-created', $send_data);

        $response = [
            'type' => $user_type,
            'job' => $job,
            'status' => 'success'
        ];

        $data = $this->jobToData($job);
        Event::fire(new JobWasCreated($job, $data, '*'));

        return $response;
    }


    /**
     * @param $job
     * @return array
     */
    public function jobToData($job)
    {

        $data = [];
        $data['job_id'] = $job->id;
        $data['from_language_id'] = $job->from_language_id;
        $data['immediate'] = $job->immediate;
        $data['duration'] = $job->duration;
        $data['status'] = $job->status;
        $data['gender'] = $job->gender;
        $data['certified'] = $job->certified;
        $data['due'] = $job->due;
        $data['job_type'] = $job->job_type;
        $data['customer_phone_type'] = $job->customer_phone_type;
        $data['customer_physical_type'] = $job->customer_physical_type;
        $data['customer_town'] = $job->town;
        $data['customer_type'] = $job->user->userMeta->customer_type;

        $due_Date = explode(" ", $job->due);
        $due_date = $due_Date[0];
        $due_time = $due_Date[1];

        $data['due_date'] = $due_date;
        $data['due_time'] = $due_time;

        $data['job_for'] = array();
        if ($job->gender != null) {
            if ($job->gender == 'male') {
                $data['job_for'][] = 'Man';
            } else if ($job->gender == 'female') {
                $data['job_for'][] = 'Kvinna';
            }
        }
        if ($job->certified != null) {
            if ($job->certified == 'both') {
                $data['job_for'][] = 'Godkänd tolk';
                $data['job_for'][] = 'Auktoriserad';
            } else if ($job->certified == 'yes') {
                $data['job_for'][] = 'Auktoriserad';
            } else if ($job->certified == 'n_health') {
                $data['job_for'][] = 'Sjukvårdstolk';
            } else if ($job->certified == 'law' || $job->certified == 'n_law') {
                $data['job_for'][] = 'Rätttstolk';
            } else {
                $data['job_for'][] = $job->certified;
            }
        }

        return $data;
    }

    /**
     * @param array $post_data
     */
    public function jobEnd($post_data = [])
    {
        $completeddate = date('Y-m-d H:i:s');
        $jobid = $post_data["job_id"];
        $job_detail = $this->model->with('translatorJobRel')->find($jobid);
        $duedate = $job_detail->due;
        $start = date_create($duedate);
        $end = date_create($completeddate);
        $diff = date_diff($end, $start);
        $interval = $diff->format('%h:%i:%s');
        $job_detail->end_at = $completeddate;
        $job_detail->status = 'completed';
        $job_detail->session_time = $interval;

        $user = $job_detail->user()->first();
        $email = !empty($job_detail->user_email) ? $job_detail->user_email : $user->email;
        $name = $user->name;
        $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job_detail->id;
        $session_explode = explode(':', $job_detail->session_time);
        $session_time = $session_explode[0] . ' tim ' . $session_explode[1] . ' min';
        $data = [
            'user'         => $user,
            'job'          => $job_detail,
            'session_time' => $session_time,
            'for_text'     => 'faktura'
        ];
        $mailer = new AppMailer();
        $mailer->send($email, $name, $subject, 'emails.session-ended', $data);

        $job_detail->save();

        $tr = $job_detail->translatorJobRel->where('completed_at', Null)->where('cancel_at', Null)->first();

        $eventUserId = ($post_data['userid'] == $job_detail->user_id) ? $tr->user_id : $job_detail->user_id;
        Event::fire(new SessionEnded($job_detail, $eventUserId));

        $user = $tr->user()->first();
        $email = $user->email;
        $name = $user->name;
        $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job_detail->id;
        $data = [
            'user'         => $user,
            'job'          => $job_detail,
            'session_time' => $session_time,
            'for_text'     => 'lön'
        ];
        $mailer->send($email, $name, $subject, 'emails.session-ended', $data);

        $tr->completed_at = $completeddate;
        $tr->completed_by = $post_data['userid'];
        $tr->save();
    }

    /**
     * Function to get all Potential jobs of user with his ID
     * @param $user_id
     * @return array
     */
    public function getPotentialJobIdsWithUserId($user_id)
    {
        $user_meta = UserMeta::where('user_id', $user_id)->first();
        $translator_type = $user_meta->translator_type;
        $job_type = 'unpaid';
        if ($translator_type == 'professional')
            $job_type = 'paid';   /*show all jobs for professionals.*/
        else if ($translator_type == 'rwstranslator')
            $job_type = 'rws';  /* for rwstranslator only show rws jobs. */
        else if ($translator_type == 'volunteer')
            $job_type = 'unpaid';  /* for volunteers only show unpaid jobs. */

        $languages = UserLanguages::where('user_id', '=', $user_id)->get();
        $userlanguage = collect($languages)->pluck('lang_id')->all();
        $gender = $user_meta->gender;
        $translator_level = $user_meta->translator_level;
        $job_ids = $this->model->getJobs($user_id, $job_type, 'pending', $userlanguage, $gender, $translator_level);
        foreach ($job_ids as $k => $v)     // checking translator town
        {
            $job = $this->model->find($v->id);
            $jobuserid = $job->user_id;
            $checktown = $this->model->checkTowns($jobuserid, $user_id);
            if (($job->customer_phone_type == 'no' || $job->customer_phone_type == '') && $job->customer_physical_type == 'yes' && $checktown == false) {
                unset($job_ids[$k]);
            }
        }
        $jobs = TeHelper::convertJobIdsInObjs($job_ids);
        return $jobs;
    }

    /**
     * @param $id
     * @param $data
     * @return mixed
     */
    public function updateJob($id, $data, $cuser)
    {
        $job = $this->model->find($id);

        $current_translator = $job->translatorJobRel->where('cancel_at', Null)->first();
        if (is_null($current_translator)) {
            $current_translator = $job->translatorJobRel->where('completed_at', '!=', Null)->first();
        }

        $log_data = [];

        $langChanged = false;

        $changeTranslator = $this->changeTranslator($current_translator, $data, $job);
        if ($changeTranslator['translatorChanged']) {
            $log_data[] = $changeTranslator['log_data'];
        }

        $changeDue = $this->changeDue($job->due, $data['due']);
        if ($changeDue['dateChanged']) {
            $old_time = $job->due;
            $job->due = $data['due'];
            $log_data[] = $changeDue['log_data'];
        }

        if ($job->from_language_id != $data['from_language_id']) {
            $old_lang = $job->from_language_id;
            $log_data[] = [
                'old_lang' => TeHelper::fetchLanguageFromJobId($job->from_language_id),
                'new_lang' => TeHelper::fetchLanguageFromJobId($data['from_language_id'])
            ];
            $job->from_language_id = $data['from_language_id'];
            $langChanged = true;
        }

        $changeStatus = $this->changeStatus($job, $data, $changeTranslator['translatorChanged']);
        if ($changeStatus['statusChanged']) {
            $log_data[] = $changeStatus['log_data'];
        }

        $job->admin_comments = $data['admin_comments'];

        $this->logger->addInfo(
            'USER #' . $cuser->id . '(' . $cuser->name . ')' . ' has updated booking <a class="openjob" href="/admin/jobs/' . $id . '">#' . $id . '</a> with data:  ',
            $log_data
        );

        $job->reference = $data['reference'];

        $job->save();

        if ($job->due <= Carbon::now()) {
            return ['Updated'];
        } else {
            if ($changeDue['dateChanged']) {
                $this->sendChangedDateNotification($job, $old_time);
            }
            if ($changeTranslator['translatorChanged']) {
                $this->sendChangedTranslatorNotification($job, $current_translator, $changeTranslator['new_translator']);
            }
            if ($langChanged) {
                $this->sendChangedLangNotification($job, $old_lang);
            }
        }
    }


    /**
     * @param $data
     * @param $user
     */
    public function acceptJob($data, $user)
    {
        $job_id = $data['job_id'];
        $job = $this->model->findOrFail($job_id);

        if ($this->model->isTranslatorAlreadyBooked($job_id, $user->id, $job->due)) {
            return [
                'status' => 'fail',
                'message' => 'Du har redan en bokning den tiden! Bokningen är inte accepterad.'
            ];
        }

        if ($job->status == 'pending' && $this->model->insertTranslatorJobRel($user->id, $job_id)) {
            $job->status = 'assigned';
            $job->save();

            $user = $job->user()->first();
            $mailer = new AppMailer();

            $email = !empty($job->user_email) ? $job->user_email : $user->email;
            $name = $user->name;
            $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
            $data = [
                'user' => $user,
                'job'  => $job
            ];
            $mailer->send($email, $name, $subject, 'emails.job-accepted', $data);

            $jobs = $this->getPotentialJobs($user);

            return [
                'status' => 'success',
                'list' => json_encode(['jobs' => $jobs, 'job' => $job], true)
            ];
        }

        return [
            'status' => 'fail'
        ];
    }


    /*Function to accept the job with the job id*/
    public function acceptJobWithId($job_id, $cuser)
    {
        $job = $this->model->findOrFail($job_id);
        $response = [];

        if ($this->model->isTranslatorAlreadyBooked($job_id, $cuser->id, $job->due)) {
            $response['status'] = 'fail';
            $response['message'] = 'Du har redan en bokning den tiden ' . $job->due . '. Du har inte fått denna tolkning';
        } elseif ($job->status == 'pending' && $this->model->insertTranslatorJobRel($cuser->id, $job_id)) {
            $job->status = 'assigned';
            $job->save();
            $user = $job->user()->first();
            $mailer = new AppMailer();

            $email = !empty($job->user_email) ? $job->user_email : $user->email;
            $name = $user->name;
            $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
            $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
            $data = [
                'user' => $user,
                'job'  => $job
            ];
            $mailer->send($email, $name, $subject, 'emails.job-accepted', $data);

            $notification_data = [
                'notification_type' => 'job_accepted'
            ];
            $msg_text = [
                "en" => 'Din bokning för ' . $language . ' translators, ' . $job->duration . 'min, ' . $job->due . ' har accepterats av en tolk. Vänligen öppna appen för att se detaljer om tolken.'
            ];
            if ($this->isNeedToSendPush($user->id)) {
                $users_array = [$user];
                $this->sendPushNotificationToSpecificUsers($users_array, $job_id, $notification_data, $msg_text, $this->isNeedToDelayPush($user->id));
            }

            $response['status'] = 'success';
            $response['list']['job'] = $job;
            $response['message'] = 'Du har nu accepterat och fått bokningen för ' . $language . ' tolk ' . $job->duration . 'min ' . $job->due;
        } else {
            $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
            $response['status'] = 'fail';
            $response['message'] = 'Denna ' . $language . ' tolkning ' . $job->duration . 'min ' . $job->due . ' har redan accepterats av annan tolk. Du har inte fått denna tolkning';
        }

        return $response;
    }

    public function cancelJobAjax($data, $user)
    {
        $response = [];

        $job_id = $data['job_id'];
        $job = $this->model->findOrFail($job_id);
        $translator = $this->model->getJobsAssignedTranslatorDetail($job);

        if ($user->is('customer')) {
            $job->withdraw_at = Carbon::now();

            if ($job->withdraw_at->diffInHours($job->due) >= 24) {
                $job->status = 'withdrawbefore24';
            } else {
                $job->status = 'withdrawafter24';
            }

            $job->save();
            Event::fire(new JobWasCanceled($job));

            $response['status'] = 'success';
            $response['jobstatus'] = 'success';

            if ($translator) {
                $notification_data = [
                    'notification_type' => 'job_cancelled'
                ];
                $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
                $msg_text = [
                    "en" => 'Kunden har avbokat bokningen för ' . $language . ' tolk, ' . $job->duration . 'min, ' . $job->due . '. Var god och kolla dina tidigare bokningar för detaljer.'
                ];

                if ($this->isNeedToSendPush($translator->id)) {
                    $this->sendPushNotificationToSpecificUsers([$translator], $job_id, $notification_data, $msg_text, $this->isNeedToDelayPush($translator->id)); // send Session Cancel Push to Translator
                }
            }
        } else {
            if ($job->due->diffInHours(Carbon::now()) > 24) {
                $customer = $job->user()->first();

                if ($customer) {
                    $notification_data = [
                        'notification_type' => 'job_cancelled'
                    ];
                    $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
                    $msg_text = [
                        "en" => 'Er ' . $language . ' tolk, ' . $job->duration . 'min ' . $job->due . ', har avbokat tolkningen. Vi letar nu efter en ny tolk som kan ersätta denne. Tack.'
                    ];

                    if ($this->isNeedToSendPush($customer->id)) {
                        $this->sendPushNotificationToSpecificUsers([$customer], $job_id, $notification_data, $msg_text, $this->isNeedToDelayPush($customer->id)); // send Session Cancel Push to Customer
                    }
                }

                $job->status = 'pending';
                $job->created_at = now();
                $job->will_expire_at = TeHelper::willExpireAt($job->due, now());
                $job->save();

                $this->model->deleteTranslatorJobRel($translator->id, $job_id);

                $data = $this->jobToData($job);
                $this->sendNotificationTranslator($job, $data, $translator->id); // send Push to all suitable translators

                $response['status'] = 'success';
            } else {
                $response['status'] = 'fail';
                $response['message'] = 'Du kan inte avboka en bokning som sker inom 24 timmar genom DigitalTolk. Vänligen ring på +46 73 75 86 865 och gör din avbokning over telefon. Tack!';
            }
        }

        return $response;
    }


    /*Function to get the potential jobs for paid,rws,unpaid translators*/
    public function getPotentialJobs($cuser)
    {
        $cuser_meta = $cuser->userMeta;
        $job_type = 'unpaid';
        $translator_type = $cuser_meta->translator_type;
        if ($translator_type == 'professional')
            $job_type = 'paid';   /*show all jobs for professionals.*/
        else if ($translator_type == 'rwstranslator')
            $job_type = 'rws';  /* for rwstranslator only show rws jobs. */
        else if ($translator_type == 'volunteer')
            $job_type = 'unpaid';  /* for volunteers only show unpaid jobs. */

        $languages = UserLanguages::where('user_id', '=', $cuser->id)->get();
        $userlanguage = collect($languages)->pluck('lang_id')->all();
        $gender = $cuser_meta->gender;
        $translator_level = $cuser_meta->translator_level;
        /*Call the town function for checking if the job physical, then translators in one town can get job*/
        $job_ids = $this->model->getJobs($cuser->id, $job_type, 'pending', $userlanguage, $gender, $translator_level);
        foreach ($job_ids as $k => $job) {
            $jobuserid = $job->user_id;
            $job->specific_job = $this->model->assignedToPaticularTranslator($cuser->id, $job->id);
            $job->check_particular_job = $this->model->checkParticularJob($cuser->id, $job);
            $checktown = $this->model->checkTowns($jobuserid, $cuser->id);

            if ($job->specific_job == 'SpecificJob')
                if ($job->check_particular_job == 'userCanNotAcceptJob')
                    unset($job_ids[$k]);

            if (($job->customer_phone_type == 'no' || $job->customer_phone_type == '') && $job->customer_physical_type == 'yes' && $checktown == false) {
                unset($job_ids[$k]);
            }
        }
        //        $jobs = TeHelper::convertJobIdsInObjs($job_ids);
        return $job_ids;
    }

    public function endJob($post_data)
    {
        $completeddate = date('Y-m-d H:i:s');
        $jobid = $post_data["job_id"];
        $job_detail = $this->model->with('translatorJobRel')->find($jobid);

        if ($job_detail->status != 'started')
            return ['status' => 'success'];

        $duedate = $job_detail->due;
        $start = date_create($duedate);
        $end = date_create($completeddate);
        $diff = date_diff($end, $start);
        $interval = $diff->h . ':' . $diff->i . ':' . $diff->s;
        $job = $job_detail;
        $job->end_at = date('Y-m-d H:i:s');
        $job->status = 'completed';
        $job->session_time = $interval;

        $user = $job->user()->get()->first();
        if (!empty($job->user_email)) {
            $email = $job->user_email;
        } else {
            $email = $user->email;
        }
        $name = $user->name;
        $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
        $session_explode = explode(':', $job->session_time);
        $session_time = $session_explode[0] . ' tim ' . $session_explode[1] . ' min';
        $data = [
            'user'         => $user,
            'job'          => $job,
            'session_time' => $session_time,
            'for_text'     => 'faktura'
        ];
        $mailer = new AppMailer();
        $mailer->send($email, $name, $subject, 'emails.session-ended', $data);

        $job->save();

        $tr = $job->translatorJobRel()->where('completed_at', Null)->where('cancel_at', Null)->first();

        Event::fire(new SessionEnded($job, ($post_data['user_id'] == $job->user_id) ? $tr->user_id : $job->user_id));

        $user = $tr->user()->first();
        $email = $user->email;
        $name = $user->name;
        $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
        $data = [
            'user'         => $user,
            'job'          => $job,
            'session_time' => $session_time,
            'for_text'     => 'lön'
        ];
        $mailer = new AppMailer();
        $mailer->send($email, $name, $subject, 'emails.session-ended', $data);

        $tr->completed_at = $completeddate;
        $tr->completed_by = $post_data['user_id'];
        $tr->save();
        $response['status'] = 'success';
        return $response;
    }

    public function ignoreExpiring($id)
    {
        $job = $this->model->find($id);
        $job->ignore = 1;
        $job->save();
        return ['success', 'Changes saved'];
    }

    public function ignoreExpired($id)
    {
        $job = $this->model->find($id);
        $job->ignore_expired = 1;
        $job->save();
        return ['success', 'Changes saved'];
    }

    /**
     * @param $current_translator
     * @param $data
     * @param $job
     * @return array
     */
    private function changeTranslator($current_translator, $data, $job)
    {
        $translatorChanged = false;

        if (!is_null($current_translator) || (isset($data['translator']) && $data['translator'] != 0) || $data['translator_email'] != '') {
            $log_data = [];
            if (!is_null($current_translator) && ((isset($data['translator']) && $current_translator->user_id != $data['translator']) || $data['translator_email'] != '') && (isset($data['translator']) && $data['translator'] != 0)) {
                if ($data['translator_email'] != '') $data['translator'] = User::where('email', $data['translator_email'])->first()->id;
                $new_translator = $current_translator->toArray();
                $new_translator['user_id'] = $data['translator'];
                unset($new_translator['id']);
                $new_translator = Translator::create($new_translator);
                $current_translator->cancel_at = Carbon::now();
                $current_translator->save();
                $log_data[] = [
                    'old_translator' => $current_translator->user->email,
                    'new_translator' => $new_translator->user->email
                ];
                $translatorChanged = true;
            } elseif (is_null($current_translator) && isset($data['translator']) && ($data['translator'] != 0 || $data['translator_email'] != '')) {
                if ($data['translator_email'] != '') $data['translator'] = User::where('email', $data['translator_email'])->first()->id;
                $new_translator = Translator::create(['user_id' => $data['translator'], 'job_id' => $job->id]);
                $log_data[] = [
                    'old_translator' => null,
                    'new_translator' => $new_translator->user->email
                ];
                $translatorChanged = true;
            }
            if ($translatorChanged)
                return ['translatorChanged' => $translatorChanged, 'new_translator' => $new_translator, 'log_data' => $log_data];
        }

        return ['translatorChanged' => $translatorChanged];
    }

    /**
     * @param $old_due
     * @param $new_due
     * @return array
     */
    private function changeDue($old_due, $new_due)
    {
        $dateChanged = false;
        if ($old_due != $new_due) {
            $log_data = [
                'old_due' => $old_due,
                'new_due' => $new_due
            ];
            $dateChanged = true;
            return ['dateChanged' => $dateChanged, 'log_data' => $log_data];
        }

        return ['dateChanged' => $dateChanged];
    }
}
