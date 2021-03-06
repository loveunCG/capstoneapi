<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use App\Http\Requests;
use App\Level;
use App\Question;
use Auth;
use App\Http\Requests\CreateQuizAnswersRequest;
use DateTime;
use App\User;
use Config;
use App\Error;
use App\Course;
use App\Enrolment;
use App\Role;
use App\Http\Requests\StoreMasterCodeRequest;
use Carbon\Carbon;
use Mail;

class DiagnosticController extends Controller
{
    public function __construct(){
    }

    /**
     *
     * One question from the highest skill of each track from the appropriate level
     *
     * @return \Illuminate\Http\Response
     */
    public function index(){
//for testing        return response()->json(['message' => 'Test question', 'questions'=>Question::where('id','>',880)->where('id','<',890)->get(), 'code'=>201]);

        $courses = Course::where('course', 'LIKE', '%K to 6 Math%')->pluck('id'); //K-6 math course id
        $user = Auth::user();
        $enrolled = $user->validEnrolment($courses); //k-6 courses enrolled in

        if (!count($enrolled)) return response()->json(['message'=>'Not properly enrolled or first time user', 'code'=>203]);
        $test = count($user->currenttest)<1 ? !count($user->completedtests) ? 
        $user->tests()->create(['test'=>$user->name."'s First Diagnostic test",'description'=> $user->name."'s diagnostic test", 'diagnostic'=>TRUE]):
        $user->tests()->create(['test'=>$user->name."'s Daily test",'description'=> $user->name."'s Daily Test", 'diagnostic'=>FALSE]):
        $user->currenttest[0];
        return $test->fieldQuestions($user);                // output test questions
    }

    /**
     * Sends a list of questions of the test number to the front end
     *
     * One question from the highest skill of each track from the appropriate level
     *
     * @return \Illuminate\Http\Response
     */
    public function store(StoreMasterCodeRequest $request){
        $courses = Course::where('course', 'LIKE', '%K to 6 Math%')->pluck('id');
        $user = Auth::user();
        if (count($user->validEnrolment($courses))){
          return response()->json(['message'=>'Already enrolled in course', "code"=>404], 404);  
        }
        $check_mastercode = Enrolment::whereMastercode($request->mastercode)->first();
        if (!$check_mastercode) return response()->json(['message'=>'Your mastercode is wrong.', 'code'=>404], 404);
        if ($check_mastercode->places_alloted) {
//            $date = new DateTime('now');
            $houses = \App\House::find($check_mastercode->house_id);
            $check_mastercode->places_alloted -= 1;
            $mastercode = $check_mastercode->places_alloted < 1 ? null : $request->mastercode;
            $check_mastercode->fill(['mastercode'=>$mastercode])->save();
            $enrolment = Enrolment::firstOrNew(['user_id'=>$user->id, 'house_id'=>$check_mastercode->house_id, 'role_id'=>Role::where('role', 'LIKE', '%Student%')->first()->id]);
            $enrolment->fill(['start_date'=>new DateTime('now'),'expiry_date'=>(new DateTime('now'))->modify('+1 year'), 'payment_email'=>$check_mastercode->payment_email, 'purchaser_id'=>$check_mastercode->user_id, 'transaction_id'=>$check_mastercode->transaction_id, 'payment_status'=>$check_mastercode->payment_status, 'amount_paid'=>$check_mastercode->amount_paid, 'currency_code'=>$check_mastercode->currency_code])->save();
            $user->date_of_birth = Carbon::createFromFormat('m/d/Y',$request->date_of_birth);        
            $user->update(['firstname'=>$request->firstname, 'lastname'=>$request->lastname, 'date_of_birth'=>$user->date_of_birth]);
            $note = 'Dear '.$user->firstname.',<br><br>Thank you for enrolling in the '.$houses->description.' program!<br><br> You should be presented questions for the diagnosis test and we will start to monitor your progress from now.<br><br> You should check your progress periodically at math.pamelalim.me. <br><br>Should you have any queries, please do not hesitate to contact us at info.allgifted@gmail.com<br><br>Thank you. <br><br> <i>This is an automated machine generated by the All Gifted System on behalf of Pamela Lim (Thank you for helping me to check!!!).</i>';

            Mail::send([],[], function ($message) use ($user,$note) {
                $message->from(env("MAIL_ORDER_ADDRESS"), 'All Gifted Admin')
                        ->to($user->email)->cc('info.allgifted@gmail.com')
                        ->subject('Successful Enrolment')
                        ->setBody($note, 'text/html');
            });            

        } else return response()->json(['message'=>'There is no more places left for the mastercode you keyed in.',  'code'=>404], 404);
        return $this->index();
    }

    /**
     * Checks answers and then sends a new set of questions, according to correctness of 
     * questions.  Checks the following
     *
     * @return \Illuminate\Http\Response
     */
    public function answer(CreateQuizAnswersRequest $request){
        $user = Auth::user();
        $old_maxile = $user->maxile_level;
        $test = \App\Test::find($request->test);
        if (!$test){
            return response()->json(['message' => 'Invalid Test Number', 'code'=>405], 405);    
        }

        foreach ($request->question_id as $key=>$question_id) {
            $answered = FALSE;
            $correctness = FALSE;
            $question = Question::find($question_id);
            if (!$question){
                $user->errorlogs()->create(['error'=>'Question '.$question_id.' not found']);
                return response()->json(['message'=>'Error in question. No such question', 'code'=>403]);                
            }
           $assigned = $question->tests()->whereTestId($test->id)->first();
            if (!$assigned) {
                $user->errorlogs()->create(['error'=>'Question '.$question_id.' not assigned to '. $user->name]);
                return response()->json(['message'=>'Question not assigned to '. $user->name, 'code'=>403]);                                
            }
            if ($question->type_id == 2) {
                $answers = $request->answer[$key];
                $correct3 = sizeof($answers) > 3 ? $answers[3] == $question->answer3 ? TRUE : FALSE : TRUE;
                $correct2 = sizeof($answers) > 2 ? $answers[2] == $question->answer2 ? TRUE : FALSE : TRUE;
                $correct1 = sizeof($answers) > 1 ? $answers[1] == $question->answer1 ? TRUE : FALSE : TRUE;
                $correct = sizeof($answers) > 0 ? $answers[0] == $question->answer0 ? TRUE : FALSE : TRUE;
                $correctness = $correct + $correct1 + $correct2 + $correct3 > 3? TRUE: FALSE;
            } else $correctness = $question->correct_answer != $request->answer[$key] ? FALSE:TRUE;
            $answered = $question->answered($user, $correctness, $test); // update question_user
            $track = $question->skill->tracks->intersect($user->testedTracks()->orderBy('updated_at','desc')->get())->first();
            // calculate and saves maxile at 3 levels: skill, track and user
            $skill_maxile = $question->skill->handleAnswer($user->id, $question->difficulty_id, $correctness, $track, $test->diagnostic);
            $track_maxile = $track->calculateMaxile($user, $test->diagnostic);
            $field_maxile = $user->storefieldmaxile($track_maxile, $track->field_id);
            // find the class
            if (!$test->diagnostic) {
                $house = $track->houses->intersect(\App\House::whereIn('id', Enrolment:: whereUserId($user->id)->whereRoleId(6)->pluck('house_id'))->get())->first();
                if ($house) {
                    $enrolment = Enrolment::whereUserId($user->id)->whereRoleId(6)->whereHouseId($house->id)->first();
                    $enrolment['progress'] = round($user->tracksPassed->intersect(\App\House::find(1)->tracks)->avg('level_id')*100);
                    $enrolment->save();
                }
            }
        }
        return $test->fieldQuestions($user, $test);
    }
    /**
     * Enrolls a student 
     *
     * @return \Illuminate\Http\Response
     */
    public function mastercodeEnrol($request){
        return $request->all();
    }
}