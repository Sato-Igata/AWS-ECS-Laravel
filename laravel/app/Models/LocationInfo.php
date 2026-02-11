<?php
//app/Models/LocationInfo.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LocationInfo extends Model
{
    protected $table = 'location_info';

    protected $fillable = [
        'model_number',
        'lat',
        'lng',
        'alt',
        'stl',
        'vol',
        'time_id',
        'imsi',
        'imei',
        'type',
        'loc_data',
        'ns',
        'ew',
        'major_axis',
        'minor_axis',
        'bat',
        'is_deleted',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class, 'model_number', 'model_number');
    }
}