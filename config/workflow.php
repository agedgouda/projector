<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Universal Notes -> Action Items step
    |--------------------------------------------------------------------------
    |
    | The very first document-processing transition is protocol-independent:
    | every "Notes" document, regardless of which protocol its project uses,
    | always becomes an "Action Items" document via the same fixed AI
    | template. Every step after that is explicit and user-chosen.
    |
    */

    'intake_key' => 'intake',

    'action_items_key' => 'action_items',

    'intake_to_action_items_ai_template_id' => (int) env('INTAKE_TO_ACTION_ITEMS_TEMPLATE_ID', 5),

];
