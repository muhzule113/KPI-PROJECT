import assert from "node:assert/strict";
import test from "node:test";
import { monthlyKpiScope } from "../src/modules/access/query-scope.ts";

const base = { userId: "user-1", employeeId: "employee-1", branchId: "branch-1", active: true } as const;

test("scope query bulanan membatasi data sesuai role", () => {
  assert.deepEqual(monthlyKpiScope({ ...base, role: "EMPLOYEE" }), { employeeId: "employee-1", status: "FINALIZED" });
  assert.deepEqual(monthlyKpiScope({ ...base, role: "MANAGER" }), { managerIdSnapshot: "employee-1" });
  assert.deepEqual(monthlyKpiScope({ ...base, role: "SUPERVISOR" }), {
    OR: [
      { employeeId: "employee-1", status: "FINALIZED" },
      { supervisorIdSnapshot: "employee-1", subjectRoleSnapshot: "EMPLOYEE" },
    ],
  });
  assert.deepEqual(monthlyKpiScope({ ...base, employeeId: null, role: "EMPLOYEE" }), { id: "__none__" });
});
