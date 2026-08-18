# Phase 3: Admin Data Management Refactor — useAdminSave.ts

**Goal:** Replace localStorage-based admin data (staff registry, audit logs) with PHP API calls.

**Status:** Ready for Implementation

---

## Current State (BEFORE)

**File:** `src/hooks/useAdminSave.ts`

### What it does NOW (localStorage-based):

1. **`loadRegistry()`** → reads `registry` key from localStorage
   - Returns array of staff/doctor accounts: `[ { id, email, name, role, status, ... }, ... ]`

2. **`saveRegistry(registry)`** → writes `registry` to localStorage
   - Persists updated staff list

3. **`loadPatientRegistry()`** → reads `patient_registry` from localStorage
   - Returns array of patient accounts: `[ { id, phone, name, registerNumber, status, ... }, ... ]`

4. **`savePatientRegistry(patients)`** → writes `patient_registry` to localStorage
   - Persists updated patient list

5. **`appendAuditLog(entry)`** → appends to `audit_log` in localStorage
   - Logs: `{ timestamp, userRole, userName, action, target }`

### Problems:
- ❌ All staff/patient account changes only in browser (not persistent across devices)
- ❌ Audit logs only stored locally (lost on logout or browser clear)
- ❌ No validation of role changes or actions
- ❌ Approval workflow is manual/UI-only
- ❌ Cannot query audit logs from backend (reports, security analysis, compliance)

---

## Target State (AFTER)

### What it will do (PHP API-based):

1. **`loadRegistry()`** → GET `/api/staff/list.php`
   - Returns staff/doctor accounts from MySQL database
   - Cached in React Query for 30 seconds

2. **`saveRegistry(registry)`** → PUT `/api/staff/bulk-update.php`
   - Or POST `/api/staff/{id}/update.php` for individual updates
   - Server validates role changes, logs audit trail

3. **`loadPatientRegistry()`** → GET `/api/patients/list.php`
   - Returns patients from MySQL database
   - Cached in React Query

4. **`savePatientRegistry(patients)`** → PUT `/api/patients/bulk-update.php`
   - Or POST `/api/patients/{id}/update.php`
   - Server validates status changes

5. **`appendAuditLog(entry)`** → POST `/api/audit/create.php`
   - Server records audit trail in MySQL
   - Audit logs queryable for reports

---

## Step-by-Step Implementation

### Step 1: Create API service layer for admin/staff

**File:** `src/services/adminAPI.ts` (NEW)

```typescript
import { queryClient } from '../main'; // React Query client

// ── Staff Management ──────────────────────────────────────
export async function getStaffList(filters?: any) {
  const params = new URLSearchParams(filters || {});
  const response = await fetch(`/api/staff/list.php?${params}`, {
    headers: getAuthHeaders(),
  });
  if (!response.ok) throw new Error('Failed to fetch staff');
  return response.json();
}

export async function updateStaff(id: string, data: any) {
  const response = await fetch(`/api/staff/${id}/update.php`, {
    method: 'PUT',
    headers: getAuthHeaders(),
    body: JSON.stringify(data),
  });
  if (!response.ok) throw new Error('Failed to update staff');
  
  // Invalidate staff list cache
  queryClient.invalidateQueries({ queryKey: ['staff'] });
  return response.json();
}

export async function approveStaffAccount(id: string, role: string) {
  return updateStaff(id, { status: 'approved', role });
}

export async function rejectStaffAccount(id: string) {
  return updateStaff(id, { status: 'rejected' });
}

// ── Patient Management ────────────────────────────────────
export async function getPatientList(filters?: any) {
  const params = new URLSearchParams(filters || {});
  const response = await fetch(`/api/patients/list.php?${params}`, {
    headers: getAuthHeaders(),
  });
  if (!response.ok) throw new Error('Failed to fetch patients');
  return response.json();
}

export async function updatePatient(id: string, data: any) {
  const response = await fetch(`/api/patients/${id}/update.php`, {
    method: 'PUT',
    headers: getAuthHeaders(),
    body: JSON.stringify(data),
  });
  if (!response.ok) throw new Error('Failed to update patient');
  
  queryClient.invalidateQueries({ queryKey: ['patients'] });
  return response.json();
}

export async function approvePatientAccount(id: string) {
  return updatePatient(id, { status: 'approved' });
}

export async function rejectPatientAccount(id: string) {
  return updatePatient(id, { status: 'rejected' });
}

// ── Audit Logging ─────────────────────────────────────────
export async function createAuditLog(entry: {
  userRole: string;
  userName: string;
  action: string;
  target: string;
}) {
  const response = await fetch('/api/audit/create.php', {
    method: 'POST',
    headers: getAuthHeaders(),
    body: JSON.stringify({
      ...entry,
      timestamp: new Date().toISOString(),
    }),
  });
  if (!response.ok) {
    console.error('Failed to log audit entry');
    // Don't throw — audit failures shouldn't break UX
  }
  return response.json();
}

export async function getAuditLog(filters?: { limit?: number; offset?: number }) {
  const params = new URLSearchParams(filters as any);
  const response = await fetch(`/api/audit/list.php?${params}`, {
    headers: getAuthHeaders(),
  });
  if (!response.ok) throw new Error('Failed to fetch audit logs');
  return response.json();
}
```

---

### Step 2: Create React hooks to wrap the API layer

**File:** `src/hooks/useStaffManagement.ts` (NEW)

```typescript
import { useQuery, useMutation } from '@tanstack/react-query';
import {
  getStaffList,
  approveStaffAccount,
  rejectStaffAccount,
  updateStaff,
} from '../services/adminAPI';

export function useStaffList(filters?: any) {
  return useQuery({
    queryKey: ['staff', filters],
    queryFn: () => getStaffList(filters),
    staleTime: 30_000, // 30 seconds
  });
}

export function useApproveStaff() {
  return useMutation({
    mutationFn: ({ id, role }: { id: string; role: string }) =>
      approveStaffAccount(id, role),
    onSuccess: () => {
      // Toast success message
      import('sonner').then(({ toast }) =>
        toast.success('Staff account approved'),
      );
    },
    onError: (err: any) => {
      import('sonner').then(({ toast }) =>
        toast.error(err.message || 'Failed to approve'),
      );
    },
  });
}

export function useRejectStaff() {
  return useMutation({
    mutationFn: (id: string) => rejectStaffAccount(id),
    onSuccess: () => {
      import('sonner').then(({ toast }) =>
        toast.success('Staff account rejected'),
      );
    },
  });
}

export function useUpdateStaff() {
  return useMutation({
    mutationFn: ({ id, data }: { id: string; data: any }) =>
      updateStaff(id, data),
    onSuccess: () => {
      import('sonner').then(({ toast }) =>
        toast.success('Staff updated'),
      );
    },
  });
}
```

**File:** `src/hooks/usePatientManagement.ts` (NEW)

```typescript
import { useQuery, useMutation } from '@tanstack/react-query';
import {
  getPatientList,
  approvePatientAccount,
  rejectPatientAccount,
} from '../services/adminAPI';

export function usePatientList(filters?: any) {
  return useQuery({
    queryKey: ['patients', filters],
    queryFn: () => getPatientList(filters),
    staleTime: 30_000,
  });
}

export function useApprovePatient() {
  return useMutation({
    mutationFn: (id: string) => approvePatientAccount(id),
    onSuccess: () => {
      import('sonner').then(({ toast }) =>
        toast.success('Patient account approved'),
      );
    },
  });
}

export function useRejectPatient() {
  return useMutation({
    mutationFn: (id: string) => rejectPatientAccount(id),
    onSuccess: () => {
      import('sonner').then(({ toast }) =>
        toast.success('Patient account rejected'),
      );
    },
  });
}
```

**File:** `src/hooks/useAuditLog.ts` (NEW)

```typescript
import { useMutation, useQuery } from '@tanstack/react-query';
import { createAuditLog, getAuditLog } from '../services/adminAPI';

export function useLogAudit() {
  return useMutation({
    mutationFn: (entry: any) => createAuditLog(entry),
    // Fire and forget — don't block UX if audit fails
  });
}

export function useAuditLog(filters?: any) {
  return useQuery({
    queryKey: ['audit', filters],
    queryFn: () => getAuditLog(filters),
    staleTime: 60_000, // 1 minute
  });
}
```

---

### Step 3: Replace useAdminSave.ts calls throughout the app

#### In `App.tsx` (Pending Approvals Panel):

**BEFORE:**
```typescript
const approveStaff = (acc: DoctorAccount) => {
  const reg = loadRegistry();  // from localStorage
  const idx = reg.findIndex((d) => d.id === acc.id);
  reg[idx] = { ...reg[idx], status: 'approved', role };
  saveRegistry(reg);  // to localStorage
  refresh();
};
```

**AFTER:**
```typescript
const { mutate: approveStaff } = useApproveStaff();

const handleApproveStaff = (acc: DoctorAccount) => {
  const role = approvalRoles[acc.id] ?? acc.role ?? 'doctor';
  approveStaff({ id: acc.id, role });
};
```

#### In `Layout.tsx` (Badge counts):

**BEFORE:**
```typescript
function getPendingApprovalCount(): number {
  const registry = JSON.parse(storage.getItem('registry') ?? '[]');
  return registry.filter((a) => a.status === 'pending').length;
}
```

**AFTER:**
```typescript
function usePendingApprovalCount() {
  const { data: staffList = [] } = useStaffList();
  const { data: patientList = [] } = usePatientList();
  
  const staffPending = staffList.filter((s) => s.status === 'pending').length;
  const patientPending = patientList.filter((p) => p.status === 'pending').length;
  
  return staffPending + patientPending;
}

// In Layout component:
const pendingCount = usePendingApprovalCount();
```

---

### Step 4: Verify PHP backend endpoints

Check these exist in `public_html/api/`:

#### ✅ `/api/staff/list.php`
```php
<?php
// GET /api/staff/list.php?status=pending
// Return: [ { id, email, name, role, status, createdAt }, ... ]
```

#### ✅ `/api/staff/{id}/update.php`
```php
<?php
// PUT /api/staff/{id}/update.php
// Body: { status, role, ... }
// Return: { success: true, staff: {...} }
```

#### ✅ `/api/patients/list.php`
```php
<?php
// GET /api/patients/list.php?status=pending
// Return: [ { id, phone, name, registerNumber, status }, ... ]
```

#### ✅ `/api/patients/{id}/update.php`
```php
<?php
// PUT /api/patients/{id}/update.php
// Body: { status, ... }
// Return: { success: true, patient: {...} }
```

#### ✅ `/api/audit/create.php`
```php
<?php
// POST /api/audit/create.php
// Body: { timestamp, userRole, userName, action, target }
// Return: { success: true, id }
```

#### ✅ `/api/audit/list.php`
```php
<?php
// GET /api/audit/list.php?limit=100&offset=0
// Return: [ { id, timestamp, userRole, userName, action, target }, ... ]
```

---

### Step 5: Testing Checklist

- [ ] Staff list loaded from `/api/staff/list.php`
- [ ] Approve staff button calls `/api/staff/{id}/update.php`
- [ ] Patient list loaded from `/api/patients/list.php`
- [ ] Approve patient button calls `/api/patients/{id}/update.php`
- [ ] Audit log entry created in MySQL when staff/patient approved
- [ ] Audit log page displays entries from `/api/audit/list.php`
- [ ] No localStorage calls for registry/patient_registry
- [ ] Badge counts update after approval (React Query cache invalidation)
- [ ] Network tab shows correct API calls

---

## Files to Modify

1. **`src/services/adminAPI.ts`** ← NEW
2. **`src/hooks/useStaffManagement.ts`** ← NEW
3. **`src/hooks/usePatientManagement.ts`** ← NEW
4. **`src/hooks/useAuditLog.ts`** ← NEW
5. **`src/hooks/useAdminSave.ts`** ← DEPRECATE (can keep for reference, but don't use)
6. **`src/App.tsx`** ← Replace `loadRegistry()` with `useStaffList()`
7. **`src/Layout.tsx`** ← Replace badge count functions with React hooks
8. **`src/pages/AuditLog.tsx`** ← Replace localStorage audit log reads with `useAuditLog()`

---

## Expected Outcome

✅ All staff/patient data from MySQL database  
✅ All changes validated server-side  
✅ Audit trail automatically recorded  
✅ Real-time data consistency across devices  
✅ Ready for Phase 4: Update Patient & Visit Pages

