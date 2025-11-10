<?php

namespace Layerok\PosterPos\Controllers;

use BackendMenu;
use Backend\Classes\Controller;
use Illuminate\Http\JsonResponse;
use Layerok\PosterPos\Models\AddressSettings;
use Layerok\PosterPos\Models\Spot;
use ApplicationException;

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
        $this->addJs('/plugins/layerok/posterpos/assets/js/map.js', ['defer' => true]);
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
            'buildings'
        ]);

        return [
            'addresses' => $addresses
        ];
    }

    public function onSaveAddress()
    {
        $id = post('id');
        $address = \layerok\PosterPos\Models\Address::find($id);
        $address->name_ua = post('name_ua');
        $address->name_ru = post('name_ru');
        $address->suburb_ua = post('suburb_ua');
        $address->suburb_ru = post('suburb_ru');
        $address->buildings = post('buildings');
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
        if (post('min_amount') !== null) {
            $data['min_amount'] = post('min_amount');
        }
        if (post('delivery_price') !== null) {
            $data['delivery_price'] = post('delivery_price');
        }
        if (post('min') !== null) {
            $data['min'] = post('min');
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
    public function onToggleAddressSystem()
    {
        $enabled = \Layerok\PosterPos\Models\AddressSettings::get('enable_address_system', true);

        \Layerok\PosterPos\Models\AddressSettings::set('enable_address_system', !$enabled);

        return [
            '#toggle-address-container' => $this->makePartial('toggle_button')
        ];
    }

    public function options(): JsonResponse
    {
        $enabled = (bool) AddressSettings::get('enable_address_system');

        return response()->json(['enable_address_system' => $enabled]);
    }
    public function Addresses()
    {
        $slug = input('city_slug');

        if (!$slug) {
            return ['addresses' => []];
        }

        $city = \Layerok\PosterPos\Models\City::where('slug', $slug)->first();

        if (!$city) {
            return ['addresses' => []];
        }

        $spots = \Layerok\PosterPos\Models\Spot::with('unavailable_categories')
            ->where('city_id', $city->id)
            ->get();
        $spotMap = $spots->mapWithKeys(function ($spot) {
            return [
                $spot->id => [
                    'name' => $spot->name,
                    'unavailable_categories' => $spot->unavailable_categories->pluck('id')->toArray(),
                ],
            ];
        });


        $availableSpotIds = $spots
            ->where('temporarily_unavailable', false)
            ->pluck('id');

        $areas = \Layerok\PosterPos\Models\Area::whereIn('spot_id', $availableSpotIds)
            ->get()
            ->map(function ($area) {
                return [
                    'coords' => is_string($area->coords) ? json_decode($area->coords, true) : $area->coords,
                    'spot_id' => $area->spot_id,
                    'min_amount' => $area->min_amount,
                    'delivery_price' => $area->delivery_price,
                    'min' => $area->min,
                ];
            });

        $addresses = \Layerok\PosterPos\Models\Address::all([
            'Id',
            'name_ua',
            'name_ru',
            'suburb_ua',
            'suburb_ru',
            'buildings',
            'lon',
            'lat',
        ]);

        $result = $addresses->map(function ($address) use ($areas, $spotMap) {
            $spotId = null;
            $spotName = null;
            $unavailableCategories = null;
            $min_amount = null;
            $delivery_price = null;
            $min = null;
            foreach ($areas as $area) {
                if ($this->pointInPolygon($address->lat, $address->lon, $area['coords'])) {
                    $min_amount = $area['min_amount'];
                    $delivery_price = $area['delivery_price'];
                    $spotId = $area['spot_id'];
                    $spotName = $spotMap[$spotId]['name'] ?? null;
                    $unavailableCategories = $spotMap[$spotId]['unavailable_categories'] ?? null;
                    $min = $area['min'];
                    break;
                }
            }

            if (!$spotId) {
                return null;
            }

            return [
                'id' => $address->Id,
                'name_ua' => $address->name_ua,
                'name_ru' => $address->name_ru,
                'suburb_ua' => $address->suburb_ua,
                'suburb_ru' => $address->suburb_ru,
                'buildings' => !empty($address->buildings)
                    ? array_map(
                        'trim', // remove spaces
                        explode(',', $address->buildings)
                    )
                    : [],
                'spot_name' => $spotName,
                'min_amount' => $min_amount,
                'delivery_price' => $delivery_price,
                'min' => $min,
                'unavailable_categories' => $unavailableCategories
            ];
        })->filter();

        return ['addresses' => $result->values()];
    }
    private function pointInPolygon($x, $y, $poly)
    {
        $c = false;
        $l = count($poly);

        for ($i = 0, $j = $l - 1; $i < $l; $j = $i++) {
            $xj = $poly[$j][0];
            $yj = $poly[$j][1];
            $xi = $poly[$i][0];
            $yi = $poly[$i][1];

            $where = ($yi - $yj) * ($x - $xi) - ($xi - $xj) * ($y - $yi);

            if ($yj < $yi) {
                if ($y >= $yj && $y < $yi) {
                    if ($where == 0)
                        return true; // point on the line
                    if ($where > 0) {
                        if ($y == $yj) {
                            // ray intersects vertex
                            $prevIndex = ($j == 0) ? $l - 1 : $j - 1;
                            if ($y > $poly[$prevIndex][1]) {
                                $c = !$c;
                            }
                        } else {
                            $c = !$c;
                        }
                    }
                }
            } elseif ($yi < $yj) {
                if ($y > $yi && $y <= $yj) {
                    if ($where == 0)
                        return true; // point on the line
                    if ($where < 0) {
                        if ($y == $yj) {
                            // ray intersects vertex
                            $prevIndex = ($j == 0) ? $l - 1 : $j - 1;
                            if ($y < $poly[$prevIndex][1]) {
                                $c = !$c;
                            }
                        } else {
                            $c = !$c;
                        }
                    }
                }
            } elseif ($y == $yi && (($x >= $xj && $x <= $xi) || ($x >= $xi && $x <= $xj))) {
                return true; // point on horizontal edge
            }
        }

        return $c;
    }
}
