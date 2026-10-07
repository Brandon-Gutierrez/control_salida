<?php

namespace App\Http\Resources;

use App\Models\Premise;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Predio con su responsable y los nombres de sus motivos, tal como lo consume
 * el panel de administración.
 *
 * @mixin Premise
 */
class PremiseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $manager = $this->relationLoaded('managers')
            ? $this->managers->first()
            : $this->managers()->first(['user_id', 'name', 'premise_id']);

        return [
            'id' => $this->premise_id,
            'name' => $this->name,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'manager' => $manager ? ['user_id' => $manager->user_id, 'name' => $manager->name] : null,
            'reason_names' => $this->reasons->sortBy('name')->pluck('name')->values()->all(),
        ];
    }
}
