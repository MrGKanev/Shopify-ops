<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['channel', 'notification_type', 'store_label', 'recipient', 'status', 'error_category'])]
class NotificationDelivery extends Model {}
