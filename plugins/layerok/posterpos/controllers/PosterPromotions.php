<?php

namespace Layerok\PosterPos\Controllers;

use Backend\Classes\Controller;
use BackendMenu;
use Flash;
use Layerok\PosterPos\Classes\Promotions\PosterPromotionImporter;
use Layerok\PosterPos\Models\PosterPromotion;
use October\Rain\Exception\ApplicationException;

/**
 * Poster's own promotions, imported by hand, and the switch that lets the backend
 * apply the supported ones to online orders.
 */
class PosterPromotions extends Controller
{
    public $implement = [
        \Backend\Behaviors\ListController::class,
        \Backend\Behaviors\FormController::class,
    ];

    public $listConfig = 'config_list.yaml';

    // Only the update page: promotions come from Poster, the time window is ours.
    public $formConfig = 'config_form.yaml';

    public $requiredPermissions = ['layerok.posterpos.poster_promotions'];

    public function __construct()
    {
        parent::__construct();

        BackendMenu::setContext('Layerok.PosterPos', 'posterpos', 'posterpos-poster-promotions');
    }

    public function onImport()
    {
        $result = (new PosterPromotionImporter())->import();

        if ($result['errors']) {
            Flash::error('Ошибка импорта: ' . implode('; ', $result['errors']));
        } else {
            Flash::success(sprintf(
                'Импортировано акций: %d%s',
                $result['imported'],
                $result['removed'] ? sprintf(', удалены в Poster: %d', $result['removed']) : ''
            ));
        }

        return $this->listRefresh();
    }

    public function onToggle()
    {
        $promotion = PosterPromotion::findOrFail((int) post('id'));

        if (!$promotion->enabled && !$promotion->isImplemented()) {
            throw new ApplicationException('Эту акцию бэкенд применять не умеет: ' . $promotion->unsupportedReason());
        }

        $promotion->enabled = !$promotion->enabled;
        $promotion->save();

        Flash::success($promotion->enabled
            ? 'Акция «' . $promotion->name . '» включена'
            : 'Акция «' . $promotion->name . '» выключена');

        return $this->listRefresh();
    }
}
