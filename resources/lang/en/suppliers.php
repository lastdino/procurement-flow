<?php

declare(strict_types=1);

return [
    'title' => 'Suppliers',
    'search_placeholder' => 'Search name or code',
    'table' => [
        'name' => 'Name',
        'code' => 'Code',
        'email' => 'Email',
        'phone' => 'Phone',
        'actions' => 'Actions',
        'empty' => 'No suppliers',
    ],
    'buttons' => [
        'new_supplier' => 'New Supplier',
        'edit' => 'Edit',
        'delete' => 'Delete',
        'cancel' => 'Cancel',
        'save' => 'Save',
        'saving' => 'Saving...',
        'close' => 'Close',
    ],
    'modal' => [
        'new_title' => 'New Supplier',
        'edit_title' => 'Edit Supplier',
    ],
    'form' => [
        'name' => 'Name',
        'code' => 'Code',
        'email' => 'Email',
        'email_cc' => 'Email CC (comma separated)',
        'email_cc_placeholder' => 'cc1@example.com, cc2@example.com',
        'contact_person' => 'Contact Person',
        'phone' => 'Phone',
        'active' => 'Active',
        'active_yes' => 'Yes',
        'active_no' => 'No',
        'auto_send_po' => 'Auto Send PO',
        'address' => 'Address',
    ],
    'detail' => [
        'title' => 'Supplier Detail',
        'purchase_orders' => 'Purchase Orders',
        'empty_pos' => 'No purchase orders',
        'loading' => 'Loading...',
    ],
    'delete' => [
        'confirm_title' => 'Delete this supplier?',
        'confirm_text' => 'This action cannot be undone. You cannot delete a supplier with related purchase orders.',
        'confirm_button' => 'Delete',
        'deleting' => 'Deleting...',
        'has_pos_error' => 'Cannot delete supplier because related purchase orders exist.',
        'deleted' => 'Supplier has been deleted.',
    ],
];
