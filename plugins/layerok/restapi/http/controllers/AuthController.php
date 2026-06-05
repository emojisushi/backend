<?php

declare(strict_types=1);

namespace Layerok\Restapi\Http\Controllers;

use Cms\Classes\Page;
use Layerok\PosterPos\Models\Spot;
use Layerok\PosterPos\Models\Wishlist;
use Layerok\Restapi\Http\Requests\RegistrationRequest;
use Layerok\Restapi\Http\Requests\RegistrationWithPhoneRequest;
use October\Rain\Argon\Argon;
use October\Rain\Auth\AuthException;
use OFFLINE\Mall\Models\Customer;
use RainLab\User\Models\User as UserModel;
use ReaZzon\JWTAuth\Classes\Dto\TokenDto;
use Layerok\Restapi\Http\Requests\LoginRequest;
use Layerok\Restapi\Http\Requests\LoginWithPhoneRequest;
use Layerok\Restapi\Http\Requests\LoginWithSMSRequest;
use Layerok\PosterPos\Models\Cart;
use Illuminate\Support\Facades\Hash;
use Layerok\PosterPos\Models\SmsConfirmation;

use Lang;
use Validator;
use ValidationException;
use Auth;

use Mail;
use ApplicationException;

/**
 *
 */
class AuthController extends Controller
{

    public function __invoke(LoginRequest $loginRequest)
    {
        return $this->login($loginRequest);
    }

    /**
     * @param LoginRequest $loginRequest
     * @return array
     * @throws \ApplicationException
     */
    public function login(LoginRequest $loginRequest): array
    {
        $user = $this->userPluginResolver
            ->getProvider()
            ->authenticate($loginRequest->validated());

        if (empty($user)) {
            throw new \ApplicationException('Login failed');
        }

        // If the user doesn't have a Customer model it was created via the backend.
        // Make sure to add the Customer model now
        try {
            if (! $user->customer && ! $user->is_guest) {
                $customer            = new Customer();
                $customer->firstname = $user->name ?? "name";
                $customer->lastname  = $user->surname ?? "surname";
                $customer->user_id   = $user->id;
                $customer->is_guest  = false;
                $customer->save();

                $user->customer = $customer;
            }

            if ($user->customer->is_guest) {
                $this->JWTGuard->logout();
                throw new AuthException('offline.mall::lang.components.signup.errors.user_is_guest');
                Cart::transferSessionCartToCustomer($user->customer);
            }
        } catch (\Exception $e) {
        }
        $tokenDto = new TokenDto([
            'token' => $this->JWTGuard->login($user),
            'expires' => Argon::createFromTimestamp($this->JWTGuard->getPayload()->get('exp')),
            'user' => $user->load([
                'customer.addresses',
                'customer.orders.products.product.image_sets',
                'customer.orders.order_state'
            ]),
        ]);

        return ['data' => $tokenDto->toArray()];
    }

    /**
     * @param LoginWithPhoneRequest $loginRequest
     * @return array
     * @throws \ApplicationException
     */
    public function loginWithPhone(LoginWithPhoneRequest $loginRequest): array
    {
        // $user = $this->userPluginResolver
        //     ->getProvider()
        //     ->authenticate($loginRequest->validated());

        // if (empty($user)) {
        //     throw new \ApplicationException('Login failed');
        // }
        $credentials = $loginRequest->validated();

        $user = UserModel::where('phone', $credentials['phone'])->first();

        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            throw new \October\Rain\Auth\AuthException('Login failed');
        }

        // If the user doesn't have a Customer model it was created via the backend.
        // Make sure to add the Customer model now
        try {
            if (! $user->customer && ! $user->is_guest) {
                $customer            = new Customer();
                $customer->firstname = $user->name ?? "name";
                $customer->lastname  = $user->surname ?? "surname";
                $customer->user_id   = $user->id;
                $customer->is_guest  = false;
                $customer->save();

                $user->customer = $customer;
            }

            if ($user->customer->is_guest) {
                $this->JWTGuard->logout();
                throw new AuthException('offline.mall::lang.components.signup.errors.user_is_guest');
                Cart::transferSessionCartToCustomer($user->customer);
            }
        } catch (\Exception $e) {
        }
        $tokenDto = new TokenDto([
            'token' => $this->JWTGuard->login($user),
            'expires' => Argon::createFromTimestamp($this->JWTGuard->getPayload()->get('exp')),
            'user' => $user->load([
                'customer.addresses',
                'customer.orders.products.product.image_sets',
                'customer.orders.order_state'
            ]),
        ]);

        return ['data' => $tokenDto->toArray()];
    }

    public function loginWithSMS(LoginWithSMSRequest $loginRequest): array
    {
        $credentials = $loginRequest->validated();
        $confirmation = SmsConfirmation::where('phone', $credentials['phone'])->first();

        if (!$confirmation) {
            throw new \October\Rain\Auth\AuthException('Login failed');
        }

        if ($confirmation->last_code == $credentials['code']) {
            $confirmation->confirmed = true;
            $confirmation->save();
        } else {
            throw new \October\Rain\Auth\AuthException('Login failed');
        }
        $user = UserModel::where('phone', $credentials['phone'])->first();

        // If the user doesn't have a Customer model it was created via the backend.
        // Make sure to add the Customer model now
        try {
            if (! $user->customer && ! $user->is_guest) {
                $customer            = new Customer();
                $customer->firstname = $user->name ?? "name";
                $customer->lastname  = $user->surname ?? "surname";
                $customer->user_id   = $user->id;
                $customer->is_guest  = false;
                $customer->save();

                $user->customer = $customer;
            }

            if ($user->customer->is_guest) {
                $this->JWTGuard->logout();
                throw new AuthException('offline.mall::lang.components.signup.errors.user_is_guest');
                Cart::transferSessionCartToCustomer($user->customer);
            }
        } catch (\Exception $e) {
        }
        $tokenDto = new TokenDto([
            'token' => $this->JWTGuard->login($user),
            'expires' => Argon::createFromTimestamp($this->JWTGuard->getPayload()->get('exp')),
            'user' => $user->load([
                'customer.addresses',
                'customer.orders.products.product.image_sets',
                'customer.orders.order_state'
            ]),
        ]);

        return ['data' => $tokenDto->toArray()];
    }

    public function register(RegistrationRequest $registrationRequest)
    {
        $user = $this->userPluginResolver
            ->getProvider()
            ->register($registrationRequest->validated());

        if (empty($user)) {
            throw new \ApplicationException('Registration failed');
        }

        $user->created_ip_address = request()->ip();
        $user->save();

        try {
            $customer            = new Customer();
            $customer->firstname = $user->name;
            $customer->lastname  = $user->surname;
            $customer->user_id   = $user->id;
            $customer->is_guest  = false;
            $customer->save();

            $user->customer = $customer;

            Cart::transferSessionCartToCustomer($user->customer);
            Wishlist::transferToCustomer($user->customer);
        } catch (\Exception $e) {
            // $user->delete();
            // throw new \ApplicationException('Registration failed');
        }


        if ($this->userPluginResolver->getResolver()->initActivation($user) !== 'on') {
            return ['message' => 'User created'];
        }

        request()->request->add([
            'username' => $user->username,   // phone number stored as username
            'password' => $registrationRequest->password,
        ]);

        return app()->call(AuthController::class);
    }

    public function registerWithPhone(RegistrationWithPhoneRequest $registrationRequest)
    {
        $user = $this->userPluginResolver
            ->getProvider()
            ->register($registrationRequest->validated());

        if (empty($user)) {
            throw new \ApplicationException('Registration failed');
        }

        $user->created_ip_address = request()->ip();
        $user->save();

        try {
            $customer            = new Customer();
            $customer->firstname = $user->name;
            $customer->lastname  = $user->surname;
            $customer->user_id   = $user->id;
            $customer->is_guest  = false;
            $customer->save();

            $user->customer = $customer;

            Cart::transferSessionCartToCustomer($user->customer);
            Wishlist::transferToCustomer($user->customer);
        } catch (\Exception $e) {
            // $user->delete();
            // throw new \ApplicationException('Registration failed');
        }


        if ($this->userPluginResolver->getResolver()->initActivation($user) !== 'on') {
            return ['message' => 'User created'];
        }

        request()->request->add([
            'username' => $user->username,   // phone number stored as username
            'password' => $registrationRequest->password,
        ]);

        return app()->call(AuthController::class);
    }

    public function resetPassword()
    {
        $rules = [
            'code'     => 'required',
            'password' => 'required|between:' . UserModel::getMinPasswordLength() . ',255'
        ];

        $validation = Validator::make(post(), $rules);
        if ($validation->fails()) {
            throw new ValidationException($validation);
        }

        $errorFields = ['code' => Lang::get(/*Invalid activation code supplied.*/'rainlab.user::lang.account.invalid_activation_code')];

        /*
         * Break up the code parts
         */
        $parts = explode('!', post('code'));
        if (count($parts) != 2) {
            throw new ValidationException($errorFields);
        }

        list($userId, $code) = $parts;

        if (!strlen(trim($userId)) || !strlen(trim($code)) || !$code) {
            throw new ValidationException($errorFields);
        }

        if (!$user = Auth::findUserById($userId)) {
            throw new ValidationException($errorFields);
        }

        if (!$user->attemptResetPassword($code, post('password'))) {
            throw new ValidationException($errorFields);
        }

        // Check needed for compatibility with legacy systems
        if (method_exists(\RainLab\User\Classes\AuthManager::class, 'clearThrottleForUserId')) {
            Auth::clearThrottleForUserId($user->id);
        }
    }

    public function restorePassword()
    {
        $redirect_url = input('redirect_url') ?? Page::url('reset.htm');

        $rules = [
            'email' => 'required|email|between:6,255'
        ];

        $validation = Validator::make(post(), $rules);
        if ($validation->fails()) {
            throw new ValidationException($validation);
        }

        $user = UserModel::findByEmail(post('email'));
        if (!$user || $user->is_guest) {
            throw new ApplicationException(Lang::get(/*A user was not found with the given credentials.*/'rainlab.user::lang.account.invalid_user'));
        }

        $code = implode('!', [$user->id, $user->getResetPasswordCode()]);

        $link = $redirect_url . '/' . $code;

        $data = [
            'name' => $user->name,
            'username' => $user->username,
            'link' => $link,
            'code' => $code
        ];

        Mail::send('rainlab.user::mail.restore', $data, function ($message) use ($user) {
            $message->to($user->email, $user->full_name);
        });
    }
}
