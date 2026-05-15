<?php

use App\Models\ForumPost;
use App\Models\ForumTopic;
use App\Models\Lesson;

/*
|--------------------------------------------------------------------------
| Fileable Morph Map — Single source of truth
|--------------------------------------------------------------------------
|
| Whitelists the polymorphic types that can be attached via the `File` model
| (column `fileable_type`). Used by :
|
|   - AppServiceProvider::boot()                → enforceMorphMap(...)
|   - app/Http/Requests/UploadFileRequest.php   → validation `in:...`
|   - app/Http/Controllers/API/FileController.php::index → filter whitelist
|
| Adding a new fileable type ?
|   1. Add a key/class pair below.
|   2. Add `morphMany(File::class, 'fileable')` to the target model.
|   3. Done. No other place to update.
|
| Reference : PRODUCTION_STANDARDS.md §1.5 Validation Systématique +
|             OWASP A01 Broken Access Control (issue #10 / #17).
|
*/

return [

    'morph_map' => [
        'lesson'      => Lesson::class,
        'forum_topic' => ForumTopic::class,
        'forum_post'  => ForumPost::class,
    ],

];
