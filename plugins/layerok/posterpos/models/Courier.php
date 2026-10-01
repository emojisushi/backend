<?php namespace Layerok\PosterPos\Models;

use Model;
use poster\src\PosterApi;

/**
 * A courier, mapped to the Poster employee whose id appears on deliveries.
 */
class Courier extends Model
{
    public $table = 'layerok_posterpos_couriers';

    use \October\Rain\Database\Traits\Validation;

    protected $fillable = [
        'name',
        'phone',
        'login',
        'password',
        'poster_user_id',
        'rate_per_km',
        'active',
    ];

    public $rules = [
        'name' => 'required',
        // One Poster employee maps to one courier, otherwise a delivery's
        // courier_id would resolve to two different people.
        'poster_user_id' => 'nullable|unique:layerok_posterpos_couriers,poster_user_id',
    ];

    public $customMessages = [
        'poster_user_id.unique' => 'Этот сотрудник Poster уже назначен другому курьеру.',
    ];

    /**
     * The unique rule has to ignore the record being edited, otherwise saving a
     * courier without changing anything would collide with itself.
     */
    public function beforeValidate()
    {
        if ($this->exists) {
            $this->rules['poster_user_id'] =
                'nullable|unique:layerok_posterpos_couriers,poster_user_id,' . $this->id;
        }
    }

    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    /**
     * Poster employees, for the mapping dropdown. Poster has no courier flag, so
     * every employee is offered and the choice is made by whoever knows the team.
     */
    public function getPosterUserIdOptions(): array
    {
        try {
            PosterApi::init(config('poster'));
            $result = (object) PosterApi::access()->getEmployees([]);

            if (isset($result->error)) {
                return [];
            }

            return collect($result->response ?? [])
                ->mapWithKeys(function ($employee) {
                    $employee = (object) $employee;
                    $name = trim((string) ($employee->name ?? '')) ?: ('ID ' . $employee->user_id);

                    return [(int) $employee->user_id => $name . ' (#' . $employee->user_id . ')'];
                })
                ->all();
        } catch (\Throwable $e) {
            return [];
        }
    }
}
