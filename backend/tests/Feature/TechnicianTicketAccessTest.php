<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\KpiPeriod;
use App\Models\ServiceTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TechnicianTicketAccessTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function makeTicket(?string $technicianEmail = null): ServiceTicket
    {
        $period = KpiPeriod::where('status', 'OPEN')->first();
        $technicianId = null;
        if ($technicianEmail) {
            $technicianId = Employee::where('email', $technicianEmail)->first()->id;
        }

        return ServiceTicket::create([
            'ticket_number' => 'SRV-TEST-'.random_int(1000, 9999),
            'customer_name' => 'Test Customer',
            'customer_phone' => '08123456789',
            'device_brand' => 'Apple',
            'device_model' => 'iPhone 13',
            'initial_complaint' => 'Layar retak',
            'status' => 'intake',
            'technician_employee_id' => $technicianId,
            'branch_id' => 1,
            'period_id' => $period->id,
        ]);
    }

    public function test_technician_can_access_own_ticket(): void
    {
        $userTek = User::where('email', 'teknisi@toko.com')->first();
        $ticket = $this->makeTicket('teknisi@toko.com');

        $this->actingAs($userTek, 'sanctum')
            ->getJson("/api/v1/operational/tickets/{$ticket->id}")
            ->assertOk();
    }

    public function test_technician_can_list_unassigned_ticket_to_claim(): void
    {
        $userTek = User::where('email', 'teknisi@toko.com')->first();
        $ticket = $this->makeTicket();

        $response = $this->actingAs($userTek, 'sanctum')
            ->getJson('/api/v1/operational/tickets')
            ->assertOk();

        $this->assertTrue(collect($response->json('data'))->contains(
            fn (array $row): bool => (string) ($row['id'] ?? '') === (string) $ticket->id
                && in_array('assign', $row['available_actions'] ?? [], true),
        ));
    }

    public function test_supervisor_can_list_unassigned_branch_ticket_to_assign(): void
    {
        $supervisor = User::where('email', 'supervisor@toko.com')->first();
        $ticket = $this->makeTicket();

        $response = $this->actingAs($supervisor, 'sanctum')
            ->getJson('/api/v1/operational/tickets')
            ->assertOk();

        $this->assertTrue(collect($response->json('data'))->contains(
            fn (array $row): bool => (string) ($row['id'] ?? '') === (string) $ticket->id
                && in_array('assign', $row['available_actions'] ?? [], true),
        ));
    }

    public function test_technician_cannot_access_other_technician_ticket(): void
    {
        $userTek = User::where('email', 'teknisi@toko.com')->first();
        // Buat tiket milik teknisi lain (kasir tidak ada, pakai teknisi dengan user berbeda)
        // Kita buat tiket dengan teknisi = userTek sendiri supaya di-claim, lalu coba akses tiket orang lain
        $otherTicket = $this->makeTicket('teknisi@toko.com');
        // Set ke teknisi berbeda: pakai employee gudang sebagai "teknisi lain" tidak valid (bukan POS-TEK)
        // Simulasikan tiket milik teknisi lain dengan employee id yang berbeda
        $empTek = Employee::where('email', 'teknisi@toko.com')->first();
        $otherTekEmp = Employee::where('email', 'cs@toko.com')->first(); // dummy, bukan teknisi

        // Buat tiket dengan teknisi selain userTek (pakai id acak yang bukan miliknya)
        $ticketOther = ServiceTicket::create([
            'ticket_number' => 'SRV-TEST-OTHER-'.random_int(1000, 9999),
            'customer_name' => 'Customer Lain',
            'customer_phone' => '08987654321',
            'device_brand' => 'Samsung',
            'device_model' => 'A54',
            'initial_complaint' => 'Baterai cepat habis',
            'status' => 'in_progress',
            'technician_employee_id' => $otherTekEmp->id,
            'branch_id' => 1,
        ]);

        $this->actingAs($userTek, 'sanctum')
            ->getJson("/api/v1/operational/tickets/{$ticketOther->id}")
            ->assertForbidden();
    }

    public function test_technician_cannot_complete_other_technician_ticket(): void
    {
        $userTek = User::where('email', 'teknisi@toko.com')->first();
        $otherTekEmp = Employee::where('email', 'cs@toko.com')->first();

        $ticketOther = ServiceTicket::create([
            'ticket_number' => 'SRV-TEST-OTHER-2-'.random_int(1000, 9999),
            'customer_name' => 'Customer Lain',
            'customer_phone' => '08987654321',
            'device_brand' => 'Samsung',
            'device_model' => 'A54',
            'initial_complaint' => 'Baterai cepat habis',
            'status' => 'in_progress',
            'technician_employee_id' => $otherTekEmp->id,
            'branch_id' => 1,
        ]);

        $this->actingAs($userTek, 'sanctum')
            ->postJson("/api/v1/operational/tickets/{$ticketOther->id}/complete", [
                'row_version' => $ticketOther->row_version,
                'result_status' => 'success',
                'diagnosis_notes' => 'test',
                'action_notes' => 'test',
                'qc_checklist' => ['display' => true],
            ])
            ->assertForbidden();
    }

    public function test_technician_cannot_assign_ticket_to_other_technician(): void
    {
        $userTek = User::where('email', 'teknisi@toko.com')->first();
        $ticket = $this->makeTicket(); // unassigned
        $empCs = Employee::where('email', 'cs@toko.com')->first(); // target "teknisi lain" tidak valid

        $this->actingAs($userTek, 'sanctum')
            ->postJson("/api/v1/operational/tickets/{$ticket->id}/assign", [
                'row_version' => $ticket->row_version,
                'technician_employee_id' => $empCs->id,
            ])
            ->assertForbidden();
    }

    public function test_technician_can_claim_unassigned_ticket(): void
    {
        $userTek = User::where('email', 'teknisi@toko.com')->first();
        $ticket = $this->makeTicket(); // unassigned

        $this->actingAs($userTek, 'sanctum')
            ->postJson("/api/v1/operational/tickets/{$ticket->id}/assign", ['row_version' => $ticket->row_version])
            ->assertOk();

        $this->assertEquals($userTek->employee->id, $ticket->fresh()->technician_employee_id);
    }

    public function test_technician_cannot_create_ticket(): void
    {
        $userTek = User::where('email', 'teknisi@toko.com')->first();

        $this->actingAs($userTek, 'sanctum')
            ->postJson('/api/v1/operational/tickets', [
                'customer_name' => 'Test',
                'customer_phone' => '08123456789',
                'device_brand' => 'Apple',
                'device_model' => 'iPhone 13',
                'initial_complaint' => 'Layar retak',
            ])
            ->assertForbidden();
    }

    public function test_pelayan_can_create_service_note_for_themselves(): void
    {
        $userPelayan = User::where('email', 'cs@toko.com')->first();
        $pelayan = Employee::where('email', 'cs@toko.com')->first();

        $response = $this->actingAs($userPelayan, 'sanctum')
            ->postJson('/api/v1/operational/tickets', [
                'customer_name' => 'Pelanggan Baru',
                'customer_phone' => '081298765432',
                'device_brand' => 'Samsung',
                'device_model' => 'Galaxy A54',
                'initial_complaint' => 'Baterai cepat habis',
            ])
            ->assertCreated();

        $this->assertDatabaseHas('service_tickets', [
            'id' => $response->json('data.id'),
            'intake_by_employee_id' => $pelayan->id,
            'cashier_employee_id' => null,
            'status' => 'intake',
        ]);
    }

    public function test_kasir_cannot_create_service_ticket_for_pelayan(): void
    {
        $userKasir = User::where('email', 'kasir@toko.com')->first();
        $pelayan = Employee::where('email', 'cs@toko.com')->first();

        $this->actingAs($userKasir, 'sanctum')
            ->postJson('/api/v1/operational/tickets', [
                'customer_name' => 'Pelanggan Baru',
                'customer_phone' => '081298765432',
                'device_brand' => 'Samsung',
                'device_model' => 'Galaxy A54',
                'initial_complaint' => 'Baterai cepat habis',
                'customer_needs' => 'Butuh HP selesai sebelum hari Sabtu.',
                'pelayan_employee_id' => $pelayan->id,
            ])
            ->assertForbidden();
    }

    public function test_kasir_cannot_create_service_ticket_without_pelayan(): void
    {
        $userKasir = User::where('email', 'kasir@toko.com')->first();

        $this->actingAs($userKasir, 'sanctum')
            ->postJson('/api/v1/operational/tickets', [
                'customer_name' => 'Pelanggan Baru',
                'customer_phone' => '081298765432',
                'device_brand' => 'Samsung',
                'device_model' => 'Galaxy A54',
                'initial_complaint' => 'Baterai cepat habis',
            ])
            ->assertForbidden();
    }
}
