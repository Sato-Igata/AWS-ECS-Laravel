<?php
//app/Models/Product.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $table = 'products';

    protected $fillable = [
        'model_number',
        'model_name',
        'user_id',
        'is_deleted',
    ];

    public function locations()
    {
        return $this->hasMany(LocationInfo::class, 'model_number', 'model_number');
    }
}