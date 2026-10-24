<?php

namespace Tests\Fixtures;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToSppg;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Model uji sementara (tabel dibuat di setUp tes) untuk SppgScope, Policy, dan Auditable.
 */
class RbacTestItem extends Model
{
    use Auditable, BelongsToSppg, SoftDeletes;

    protected $table = 'rbac_test_items';

    protected $fillable = ['sppg_id', 'nama', 'created_by', 'secret', 'catatan_rahasia'];

    protected $hidden = ['catatan_rahasia'];

    public static function createTable(): void
    {
        if (Schema::hasTable('rbac_test_items')) {
            return;
        }

        Schema::create('rbac_test_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sppg_id')->nullable();
            $table->string('nama');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('secret')->nullable();
            $table->string('catatan_rahasia')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }
}
