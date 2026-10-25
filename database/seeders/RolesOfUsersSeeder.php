<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Illuminate\Support\Facades\Schema;

class RolesOfUsersSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Detect if 'created_by' column exists in roles table
        $hasCreatedBy = Schema::hasColumn('roles', 'created_by');

        /**
         * =============================
         * 1️ ROLE MEDICAL BOARD MEMBER
         * =============================
         */
        $medicalBoardPermissions = [
            'View Referral Dashboard',

            'Create Insurance',
            'Update Insurance',
            'Delete Insurance',
            'View Insurance',

            // 'Patient Module',
            // 'Create Patient',
            // 'Update Patient',
            // 'Delete Patient',
            // 'View Patient',

            'Create Patient List',
            'Update Patient List',
            'Delete Patient List',
            'View Patient List',

            'View Patient History',
            'Create Patient History',
            'Update Patient History',
            'Delete Patient History',

            'View Reason',
            'View Location',
            'View Diagnoses',

            'View History',

            'Medical Board Report',
            'View Report',
        ];

        foreach ($medicalBoardPermissions as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }

        $medicalBoardRoleData = ['name' => 'ROLE MEDICAL BOARD MEMBER', 'guard_name' => 'web'];
        if ($hasCreatedBy) {
            $medicalBoardRoleData['created_by'] = 1;
        }

        $medicalBoardRole = Role::firstOrCreate(['name' => 'ROLE MEDICAL BOARD MEMBER'], $medicalBoardRoleData);
        $medicalBoardRole->syncPermissions($medicalBoardPermissions);


        /**
         * =============================
         * 2️ ROLE ACCOUNTANT
         * =============================
         */
        $accountantPermissions = [
            'View Referral Dashboard',

            'Bill Module',
            'Create Bill',
            'Update Bill',
            'Delete Bill',
            'View Bill',

            'Create Payment',
            'Update Payment',
            'Delete Payment',
            'View Payment',

            'View BillFile',
            'View Bill Item',

            'Accountant Report',
            'View Report',
        ];

        foreach ($accountantPermissions as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }

        $accountantRoleData = ['name' => 'ROLE ACCOUNTANT', 'guard_name' => 'web'];
        if ($hasCreatedBy) {
            $accountantRoleData['created_by'] = 1;
        }

        $accountantRole = Role::firstOrCreate(['name' => 'ROLE ACCOUNTANT'], $accountantRoleData);
        $accountantRole->syncPermissions($accountantPermissions);


        /**
         * =============================
         * 3️ ROLE DIRECTOR GENERAL (DG)
         * =============================
         */
        $dgPermissions = [
            'View Referral Dashboard',

            'Create ReferralLetter',
            'Update ReferralLetter',
            'Delete ReferralLetter',
            'View ReferralLetter',

            'Referral Module',
            'Create Referral',
            'Update Referral',
            'Delete Referral',
            'View Referral',

            'Create Hospital Letter',
            'Update Hospital Letter',
            'Delete Hospital Letter',
            'View Hospital Letter',

            'Create FollowUp',
            'View FollowUp',

            'View Hospital',
            'View ReferralType',
            'View Diagnoses',

            'View Telemedicine',

            'View Reason',

            'Director General Report',
            'View Report',
        ];

        foreach ($dgPermissions as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }

        $dgRoleData = ['name' => 'ROLE DIRECTOR GENERAL', 'guard_name' => 'web'];
        if ($hasCreatedBy) {
            $dgRoleData['created_by'] = 1;
        }

        $dgRole = Role::firstOrCreate(['name' => 'ROLE DIRECTOR GENERAL'], $dgRoleData);
        $dgRole->syncPermissions($dgPermissions);


        /**
         * =============================
         * 4️ ROLE BILL VERIFICATION OFFICER
         * =============================
         */
        $billVerificationPermissions = [
            'View Referral Dashboard',

            'Referral Module',
            'Create Referral',
            'Update Referral',
            'Delete Referral',
            'View Referral',

            'View BillFile',
            'Create BillFile',
            'Update BillFile',
            'Delete BillFile',

            'Bill Module',
            'Create Bill',
            'Update Bill',
            'Delete Bill',
            'View Bill',

            'View Bill Item',
            'Create Bill Item',
            'Update Bill Item',
            'Delete Bill Item',

            'Create FollowUp',
            'Update FollowUp',
            'Delete FollowUp',
            'View FollowUp',

            'Create Hospital Letter',
            'Update Hospital Letter',
            'Delete Hospital Letter',
            'View Hospital Letter',

            'View Reason',
            'View Location',

            'Patient Module',
            'Create Patient',
            'Update Patient',
            'Delete Patient',
            'View Patient',

            'Create Patient List',
            'Update Patient List',
            'Delete Patient List',
            'View Patient List',

            'View Patient History',
            'Create Patient History',
            'Update Patient History',
            'Delete Patient History',

            'View Diagnoses',
            'View Hospital',

            'Verification Report',
            'View Report',

            'View Telemedicine',
            'Schedule Telemedicine Appointment',
        ];

        foreach ($billVerificationPermissions as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }

        $billVerificationRoleData = ['name' => 'ROLE BILL VERIFICATION OFFICER', 'guard_name' => 'web'];
        if ($hasCreatedBy) {
            $billVerificationRoleData['created_by'] = 1;
        }

        $billVerificationRole = Role::firstOrCreate(['name' => 'ROLE BILL VERIFICATION OFFICER'], $billVerificationRoleData);
        $billVerificationRole->syncPermissions($billVerificationPermissions);

        /**
         * =============================
         * 5 ROLE HOSPITAL USER
         * =============================
         */
        $hospitalUserPermissions = [
            'View Referral Dashboard',

            'Patient Module',
            'Create Patient',
            'Update Patient',
            'View Patient',

            'Create Patient History',
            'Update Patient History',
            'View History',
            'View Reason',
            'View Diagnoses',
            'View Location',

            'View Telemedicine',
            'Create Telemedicine Request',
            'Review Telemedicine Appointment',
            'Manage Telemedicine Report',
            'Record Telemedicine Decision',
        ];

        foreach ($hospitalUserPermissions as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }

        $hospitalRoleData = ['name' => 'ROLE HOSPITAL USER', 'guard_name' => 'web'];
        if ($hasCreatedBy) $hospitalRoleData['created_by'] = 1;
        $hospitalRole = Role::firstOrCreate(['name' => 'ROLE HOSPITAL USER'], $hospitalRoleData);
        $hospitalRole->syncPermissions($hospitalUserPermissions);



        /**
         * =============================
         * 6 ROLE MKURUGENZI TIBA
         * =============================
         */
        $mkurugenziTibaPermissions = [
            'View Referral Dashboard',

            'View Patient History',
            'Update Patient History',
            'Review Patient History',
            'Forward Patient History to Board',
            'Approve Patient History',
            'Send Patient History to DG',
            'View History',
            'View Reason',
            'View Location',

            'View Telemedicine',
            'Create Telemedicine Request',
        ];

        foreach ($mkurugenziTibaPermissions as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }

        $mkurugenziTibaRoleData = ['name' => 'ROLE MKURUGENZI TIBA', 'guard_name' => 'web'];
        if ($hasCreatedBy) $mkurugenziTibaRoleData['created_by'] = 1;
        $mkurugenziTibaRole = Role::firstOrCreate(['name' => 'ROLE MKURUGENZI TIBA'], $mkurugenziTibaRoleData);
        $mkurugenziTibaRole->syncPermissions($mkurugenziTibaPermissions);

        /**
         * =============================
         * 7 ROLE TELEMEDICINE COORDINATOR
         * =============================
         */
        $telemedicinePermissions = [
            'View Telemedicine',
            'Create Telemedicine Request',
            'Schedule Telemedicine Appointment',
            'Review Telemedicine Appointment',
            'Manage Telemedicine Report',
            'Record Telemedicine Decision',
        ];

        foreach ($telemedicinePermissions as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }

        $telemedicineRoleData = ['name' => 'ROLE TELEMEDICINE COORDINATOR', 'guard_name' => 'web'];
        if ($hasCreatedBy) $telemedicineRoleData['created_by'] = 1;
        $telemedicineRole = Role::firstOrCreate(['name' => 'ROLE TELEMEDICINE COORDINATOR'], $telemedicineRoleData);
        $telemedicineRole->syncPermissions($telemedicinePermissions);
    }
}
