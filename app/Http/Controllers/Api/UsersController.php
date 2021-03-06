<?php

namespace App\Http\Controllers\Api;

use App\Common\Consts\User\UserStatusConsts;
use App\Http\HttpMessage;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;

use App\Http\HttpResponse;
use App\Http\HttpStatus;
use App\Http\Requests;
use JWTAuth;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Avatar;
use JWTAuthException;
use Validator;
use Illuminate\Validation\Rule;
use App\Http\Controllers\Auth\ForgotPasswordController;
use GuzzleHttp\Client as HttpClient;
use DB;
use App\Common\utils\GeneralUtils;


class UsersController extends Controller
{
    private $user;
    public function __construct(User $user){
        $this->user = $user;
    }

    public function registerPhonenumber(Request $request){

        $validator = Validator::make($request->all(), [
            'phonenumber' => 'required|regex:/^\\+?[1-9]\\d{1,14}$/',
        ]);

        if ($validator->fails()) {
            return HttpResponse::badRequest(HttpStatus::$ERR_VALIDATION, HttpMessage::$USER_ERROR_CREATING, $validator->errors()->all());
        }

        $user = User::where('phonenumber', $request->get('phonenumber'))->where('complete_status', '=', true)->first();


        if ($user) {
            return HttpResponse::serverError(HttpStatus::$ERR_USER_EXISTS, HttpMessage::$USER_EMAIL_EXISTS,
                HttpMessage::$USER_EMAIL_EXISTS);
        }

        $verify_code = GeneralUtils::generate4VefifyCode();

        $smsStatus = GeneralUtils::generateSMS($request->get('phonenumber'), $verify_code);

        if (!$smsStatus) {
            return HttpResponse::serverError(HttpStatus::$ERR_AUTH_TWILIO, HttpMessage::$USER_ERR_SMS_SUPPORT,
                HttpMessage::$USER_ERR_SMS_SUPPORT);
        }

        $user = User::updateOrCreate(array('phonenumber'=>$request->get('phonenumber')),
             [
                'phonenumber' => $request->get('phonenumber'),
                'verify_code' => $verify_code
                
            ]);

        
        return HttpResponse::ok(HttpMessage::$USER_GO_NEXT_STEP, $user);

    }

    public function verify_sms_code(Request $request){
        $validator = Validator::make($request->all(), [
            'phonenumber' => 'required|regex:/^\\+?[1-9]\\d{1,14}$/',
            'verify_code' =>'required'
        ]);

        if ($validator->fails()) {
            return HttpResponse::badRequest(HttpStatus::$ERR_VALIDATION, HttpMessage::$USER_ERROR_CREATING, $validator->errors()->all());
        }

        $user = User::where('phonenumber', $request->get('phonenumber'))->where('verify_code', '=', $request->get('verify_code'))->first();


        if (!$user) {
            return HttpResponse::serverError(HttpStatus::$ERR_AUTH_TWILIO, HttpMessage::$USER_VERIFY_CODE_INVALID,
                HttpMessage::$USER_VERIFY_CODE_INVALID);
        }

        $user->verify_status = true;
        $user->save();

        return HttpResponse::ok(HttpMessage::$USER_GO_NEXT_STEP, $user);

    }

    public function registerUsername(Request $request){
        $validator = Validator::make($request->all(), [
            'phonenumber' => 'required|regex:/^\\+?[1-9]\\d{1,14}$/',
            'username' =>'required'
        ]);

        if ($validator->fails()) {
            return HttpResponse::badRequest(HttpStatus::$ERR_VALIDATION, HttpMessage::$USER_ERROR_CREATING, $validator->errors()->all());
        }

        $user = User::where('username', $request->get('username'))->where('complete_status', '=', true)->first();

        if ($user) {
            return HttpResponse::serverError(HttpStatus::$ERR_USER_EXISTS, HttpMessage::$USER_USERNAME_EXISTS,
                HttpMessage::$USER_USERNAME_EXISTS);
        }


        $user = User::updateOrCreate(array('phonenumber'=>$request->get('phonenumber')),
             [
                'username' => $request->get('username'),
                
            ]);

        
        return HttpResponse::ok(HttpMessage::$USER_GO_NEXT_STEP, $user);
    }

    public function registerAvatars(Request $request){
        $validator = Validator::make($request->all(), [
            'username' =>'required',
            'avatars' =>'required'
        ]);

        if ($validator->fails()) {
            return HttpResponse::badRequest(HttpStatus::$ERR_VALIDATION, HttpMessage::$USER_ERROR_CREATING, $validator->errors()->all());
        }

        $user = User::where('username', $request->get('username'))->where('verify_status', '=', true)->where('complete_status', '=', false)->first();

    
        if (!$user) {
            return HttpResponse::serverError(HttpStatus::$ERR_USER_UPLOAD_AVATARS, HttpMessage::$USER_NOT_UPLOAD_AVATAR,
                HttpMessage::$USER_NOT_UPLOAD_AVATAR);
        }


        $destinationPath = public_path('/avatars');
        if (!file_exists($destinationPath)) { 
            mkdir($destinationPath, 0755, true); 
        }

        if ($request->HasFile('avatars')) {
          try {
                $avatars = [];
                foreach ($request->avatars as $img) {
                    $fileName = time().'_'.$request->get('username').'.'.$img->getClientOriginalExtension();
                    $img->move($destinationPath, $fileName);
                    $avatar = new Avatar(['path' => $fileName]);
                    array_push($avatars, $avatar);
                }

                $user->avatars()->saveMany($avatars);


                $token = null;

                try {

                    if (!$token = JWTAuth::fromUser($user)) {

                        return HttpResponse::unauthorized(HttpStatus::$ERR_USER_INVALID_CREDENTIALS,
                            HttpMessage::$USER_INVALID_CREDENTIALS, HttpMessage::$USER_INVALID_CREDENTIALS);
                    }

                 
                }
                catch (JWTAuthException $e) {

                    return HttpResponse::serverError(HttpStatus::$ERR_USER_CREATE_TOKEN,
                        HttpMessage::$USER_ERR_CREATING_TOKEN, HttpMessage::$USER_ERR_CREATING_TOKEN);
                } 


                $user->complete_status = true;
                $user->save();


                $user->token = $token;
                return HttpResponse::ok(HttpMessage::$USER_CREATED_SUCCESSFULLY, $user);

            } catch (\Exception $e) {
                return HttpResponse::serverError(HttpStatus::$ERR_USER_UPLOAD_AVATARS, HttpMessage::$USER_NOT_UPLOAD_AVATAR,
                HttpMessage::$USER_NOT_UPLOAD_AVATAR);
            }  
        } 
    }

    public function LoginFirstStep(Request $request){
        $validator = Validator::make($request->all(), [
            'phonenumber' => 'required|regex:/^\\+?[1-9]\\d{1,14}$/',
            'username' =>'required'
        ]);

        if ($validator->fails()) {
            return HttpResponse::badRequest(HttpStatus::$ERR_VALIDATION, HttpMessage::$USER_ERROR_CREATING, $validator->errors()->all());
        }

        $user = User::where('username', $request->get('username'))->where('phonenumber', '=', $request->get('phonenumber'))->where('complete_status', '=', true)->first();

        if (!$user) {
            return HttpResponse::serverError(HttpStatus::$ERR_VALIDATION, HttpMessage::$USER_INVALID_CREDENTIALS,
                HttpMessage::$USER_INVALID_CREDENTIALS);
        }

        $verify_code = GeneralUtils::generate4VefifyCode();

        $smsStatus = GeneralUtils::generateSMS($request->get('phonenumber'), $verify_code);

        if (!$smsStatus) {
            return HttpResponse::serverError(HttpStatus::$ERR_AUTH_TWILIO, HttpMessage::$USER_ERR_SMS_SUPPORT,
                HttpMessage::$USER_ERR_SMS_SUPPORT);
        }

        $user->verify_code = $verify_code;
        $user->save();

        
        return HttpResponse::ok(HttpMessage::$USER_GO_NEXT_STEP, $user);

    }

    public function LoginSecondStep(Request $request){
        $validator = Validator::make($request->all(), [
            'verify_code' => 'required',
            'username' =>'required'
        ]);

        if ($validator->fails()) {
            return HttpResponse::badRequest(HttpStatus::$ERR_VALIDATION, HttpMessage::$USER_ERROR_CREATING, $validator->errors()->all());
        }

        $user = User::where('username', $request->get('username'))->where('verify_code', '=', $request->get('verify_code'))->where('complete_status', '=', true)->first();

        if (!$user) {
            return HttpResponse::serverError(HttpStatus::$ERR_VALIDATION, HttpMessage::$USER_INVALID_CREDENTIALS,
                HttpMessage::$USER_INVALID_CREDENTIALS);
        }

        $token = null;

        try {

            if (!$token = JWTAuth::fromUser($user)) {

                return HttpResponse::unauthorized(HttpStatus::$ERR_USER_INVALID_CREDENTIALS,
                    HttpMessage::$USER_INVALID_CREDENTIALS, HttpMessage::$USER_INVALID_CREDENTIALS);
            }

         
        }
        catch (JWTAuthException $e) {

            return HttpResponse::serverError(HttpStatus::$ERR_USER_CREATE_TOKEN,
                HttpMessage::$USER_ERR_CREATING_TOKEN, HttpMessage::$USER_ERR_CREATING_TOKEN);
        } 


        return HttpResponse::ok(HttpMessage::$USER_CREATED_SUCCESSFULLY, ['token' => $token]);
    }

    
    public function testToken(Request $request){

         $user = JWTAuth::toUser($request->token);
         
         \Log::info('**************user created*******************');
         return HttpResponse::ok(HttpMessage::$USER_CREATED_SUCCESSFULLY, $user);
    }

    
}