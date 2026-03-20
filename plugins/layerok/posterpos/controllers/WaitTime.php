<?php

namespace Layerok\PosterPos\Controllers;

use BackendMenu;
use Backend\Classes\Controller;
use Layerok\PosterPos\Models\Spot;
use Layerok\PosterPos\Models\SpotZone;

class WaitTime extends Controller
{
    public $requiredPermissions = ['layerok.posterpos.wait_time'];

    public function __construct()
    {
        parent::__construct();
        BackendMenu::setContext('Layerok.PosterPos', 'posterpos', 'wait_time');
    }

    public function index()
    {
        $this->pageTitle = 'Manage Wait Time';
        $this->vars['spots'] = Spot::select(
            'id',
            'name',
            'wait_minutes_spot',
            'wait_minutes_delivery',
            'default_wait_minutes_spot',
            'default_wait_minutes_delivery'
        )->get();

    }

    public function save()
    {
        $spotsData = input('spots', []);

        foreach ($spotsData as $spotId => $data) {
            $spot = Spot::find($spotId);

            if (!$spot) {
                continue;
            }

            $spot->wait_minutes_spot = (int) ($data['wait_minutes_spot'] ?? 0);
            $spot->wait_minutes_delivery = (int) ($data['wait_minutes_delivery'] ?? 0);
            $spot->default_wait_minutes_spot = (int) ($data['default_wait_minutes_spot'] ?? 0);
            $spot->default_wait_minutes_delivery = (int) ($data['default_wait_minutes_delivery'] ?? 0);
            $spot->save();

        }

        return redirect()->back()->with('success', 'Дані збережено');
    }
}