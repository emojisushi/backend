<?php

namespace Layerok\PosterPos\Controllers;

use BackendMenu;
use Backend\Classes\Controller;
use Layerok\posterpos\Models\FcmToken;
use Layerok\Restapi\Services\FcmService;
use DB;

/**
 * Notification Backend Controller
 *
 * @link https://docs.octobercms.com/3.x/extend/system/controllers.html
 */
class Notification extends Controller
{

    /**
     * @var array required permissions
     */
    public $requiredPermissions = ['layerok.posterpos.notification'];

    /**
     * __construct the controller
     */
    public function __construct()
    {
        parent::__construct();

        BackendMenu::setContext('Layerok.Posterpos', 'posterpos', 'notification');
    }
    public function index()
    {
        $this->vars['platformCounts'] = FcmToken::query()
            ->select('platform', DB::raw('COUNT(*) as total'))
            ->groupBy('platform')
            ->orderBy('total', 'desc')
            ->get();

        $this->vars['totalTokens'] = FcmToken::count();
    }
    public function onSendNotification()
    {
        $title = post('title', 'Test notification');
        $body = post('body', 'This is a test notification');

        app(FcmService::class)->sendToAll(
            $title,
            $body
        );

        \Flash::success('Notification sent.');

        return \Redirect::refresh();
    }
}
