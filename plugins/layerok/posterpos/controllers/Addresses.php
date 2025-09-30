<?php
namespace Layerok\PosterPos\Controllers;

use BackendMenu;
use Backend\Classes\Controller;

/**
 * Cities Backend Controller
 *
 * @link https://docs.octobercms.com/3.x/extend/system/controllers.html
 */
class Addresses extends Controller
{
    public $implement = [
        \Backend\Behaviors\FormController::class,
        \Backend\Behaviors\ListController::class,
    ];
    // public function map()
    // {
    //     $records = \Layerok\PosterPos\Models\Address::all(['id', 'name_ua', 'name_ru', 'lat', 'lon']);

    //     $locations = $records->map(function ($record) {
    //         return [
    //             'lat' => $record->lat,
    //             'lng' => $record->lon,
    //             'label' => $record->name_ua,
    //         ];
    //     });

    //     $this->vars['locations'] = $locations;

    //     return $this->makePartial('partials/map_partial', [
    //         'locations' => $locations
    //     ]);
    // }
    /**
     * @var string formConfig file
     */
    public $formConfig = 'config_form.yaml';

    /**
     * @var string listConfig file
     */
    public $listConfig = 'config_list.yaml';

    /**
     * @var array required permissions
     */
    public $requiredPermissions = ['layerok.posterpos.addresses'];

    /**
     * __construct the controller
     */
    public function __construct()
    {
        parent::__construct();


        BackendMenu::setContext('Layerok.PosterPos', 'posterpos', 'addresses');
    }
    public function index()
    {
        $this->addJs('https://unpkg.com/leaflet/dist/leaflet.js');
        $this->addJs('https://unpkg.com/leaflet-draw/dist/leaflet.draw.js');
        $this->addCss('https://unpkg.com/leaflet/dist/leaflet.css');
        $this->addCss('https://unpkg.com/leaflet-draw/dist/leaflet.draw.css');

        $this->asExtension('ListController')->index();
        // $this->addViewPath($this->viewPath);
        $this->vars['mapPartial'] = $this->makePartial('partials/map_partial');
    }
    public function onLoadAddresses()
    {
        $addresses = \layerok\PosterPos\Models\Address::all([
            'Id',
            'name_ua',
            'name_ru',
            'suburb_ua',
            'suburb_ru',
            'lon',
            'lat',
        ]);

        return [
            'addresses' => $addresses
        ];
    }
    public function onUpdateAddress()
    {
        $recordId = post('id');
        $model = \layerok\PosterPos\Models\Address::find($recordId);

        $this->vars['formWidget'] = $this->makeWidget(\Backend\Widgets\Form::class, [
            'model' => $model,
            'alias' => 'addressForm',
            'config' => '$/layerok/models/address/fields.yaml'
        ]);

        return $this->makePartial('partials/update_address');
    }
    public function onSaveAddress()
    {
        $id = post('id');
        $address = \layerok\PosterPos\Models\Address::find($id);
        $address->name_ua = post('name_ua');
        $address->name_ru = post('name_ru');
        $address->suburb_ua = post('suburb_ua');
        $address->suburb_ru = post('suburb_ru');
        $address->save();
    }
    public function onAddAddress()
    {
        $address = new \layerok\PosterPos\Models\Address();
        $address->name_ua = post('name_ua');
        $address->name_ru = post('name_ru');
        $address->suburb_ua = post('suburb_ua');
        $address->suburb_ru = post('suburb_ru');
        $address->lat = post('lat');
        $address->lon = post('lon');
        $address->save();

        return ['id' => $address->id];
    }
    public function onDeleteAddress()
    {
        $id = post('id');
        $address = \layerok\PosterPos\Models\Address::find($id);
        $address->delete();
    }
    public function onBulkUpdateSuburb()
    {
        $ids = post('ids');
        $suburbUa = post('suburb_ua');
        $suburbRu = post('suburb_ru');

        if (!$ids || !$suburbUa || !$suburbRu) {
            throw new ApplicationException('Missing data');
        }

        \layerok\PosterPos\Models\Address::whereIn('id', $ids)->update([
            'suburb_ua' => $suburbUa,
            'suburb_ru' => $suburbRu
        ]);

        return [
            'message' => count($ids) . ' addresses updated'
        ];
    }
    public function onLoadAreas()
    {
        return [
            'areas' => \Layerok\PosterPos\Models\Area::all()->toArray()
        ];
    }

    public function onSaveArea()
    {
        $id = post('id');

        $data = [];

        if (post('name') !== null) {
            $data['name'] = post('name');
        }
        if (post('description') !== null) {
            $data['description'] = post('description');
        }
        if (post('coords') !== null) {
            $data['coords'] = post('coords');
        }
        if (post('spot_id') !== null) {
            $data['spot_id'] = post('spot_id');
        }
        if (post('color') !== null) {
            $data['color'] = post('color');
        }
        if ($id) {
            $area = \Layerok\PosterPos\Models\Area::find($id);
            if ($area) {
                $area->update($data);
            }
        } else {
            $area = \Layerok\PosterPos\Models\Area::create($data);
        }

        return ['id' => $area->id];
    }

    public function onDeleteArea()
    {
        $id = post('id');
        if ($id) {
            \Layerok\PosterPos\Models\Area::where('id', $id)->delete();
        }
        return ['status' => 'deleted'];
    }
    public function onLoadSpots()
    {
        return [
            'spots' => \Layerok\PosterPos\Models\Spot::all(['id', 'name'])->toArray()
        ];
    }

}
