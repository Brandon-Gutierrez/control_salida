<?php

namespace App\Repositories;

use App\Models\Premise;
use App\Models\ReasonLeave;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PremiseRepository
{
    public function getPremiseId(string $name): ?int
    {
        return Premise::where('name', $name)->value('id');
    }

    public function getAllWithReasons(): Collection
    {
        $premises = Premise::query()
            ->select('premises.id')
            ->orderBy('premises.created_at', 'desc')
            ->get();

        return collect($premises->map(function (Premise $premise) {
            $reasonNames = DB::table('reason_premise as rp')
                ->join('reason_leaves as rl', 'rl.id', '=', 'rp.reason_id')
                ->where('rp.premise_id', $premise->id)
                ->orderBy('rl.name')
                ->pluck('rl.name')
                ->values()
                ->all();

            return [
                'id' => $premise->id,
                'reason_names' => $reasonNames,
            ];
        })->all());
    }

    public function findById(int $id): ?Premise
    {
        $premise = Premise::find($id);

        if (! $premise) {
            return null;
        }

        return $this->loadLeaves($premise);
    }

    public function create(array $data, array $reasonIds = []): Premise
    {
        $premise = Premise::create($data);
        $this->syncReasonLeaves($premise->id, $reasonIds);

        return $this->loadLeaves($premise);
    }

    public function updateReasons(int $premiseId, array $reasonIds = []): Premise
    {
        $premise = Premise::findOrFail($premiseId);
        $this->syncReasonLeaves($premiseId, $reasonIds);

        return $this->loadLeaves($premise);
    }

    private function loadLeaves(Premise $premise): Premise
    {
        $leaves = ReasonLeave::query()
            ->join('reason_premise', 'reason_premise.reason_id', '=', 'reason_leaves.id')
            ->where('reason_premise.premise_id', $premise->id)
            ->select('reason_leaves.*')
            ->get();

        $premise->setRelation('leaves', $leaves);

        return $premise;
    }

    private function syncReasonLeaves(int $premiseId, array $reasonIds = []): void
    {
        $normalizedIds = array_values(array_unique(array_map('intval', $reasonIds)));

        DB::table('reason_premise')->where('premise_id', $premiseId)->delete();

        if (empty($normalizedIds)) {
            return;
        }

        $rows = [];
        $now = now();

        foreach ($normalizedIds as $reasonId) {
            $rows[] = [
                'premise_id' => $premiseId,
                'reason_id' => $reasonId,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('reason_premise')->insert($rows);
    }
}