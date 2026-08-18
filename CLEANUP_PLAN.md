# Frontend Cleanup Plan — Remove localStorage & Canister Calls

**Goal:** Eliminate all client-side storage (localStorage, IndexedDB) and blockchain/canister calls. All data must flow through PHP API only.

**Status:** In Progress

---

## 1. Files to DELETE

These files have no purpose in the PHP-backend architecture:

```
source_build/dr.armankabir-main/src/frontend/src/
├── canisterActors.tsx                 # ICP canister creation
├── backend.d.ts                       # ICP type definitions
├── lib/api.ts                         # Old canister API client (if exists)
└── declarations/                      # ICP generated files (entire dir)

package.json:
- Remove: @dfinity/*, @icp-sdk/*, @caffeineai/* dependencies
```

---

## 2. Files to REFACTOR — Business Data Cleanup

### **HIGH PRIORITY** — These files actively store/fetch business data in localStorage

#### A. `App.tsx` (CRITICAL — 2000+ lines)
- **Lines 1299-1326**: Scans `patients_*` keys from localStorage
- **Lines 1415-1427**: Loads drug reminders from `medicare_drug_reminders`
- **Lines 1502-1514**: Saves drug reminders to localStorage
- **Lines 1532-1553**: Scans localStorage for patient ID lookup

**Action:**
- Replace all patient data scans with API calls to `/api/patients/list.php`
- Drug reminders → `/api/patients/reminders/` endpoints
- Patient lookup by registerNumber → `/api/patients/get.php?register_number=X`

---

#### B. `Layout.tsx` (CRITICAL — 1760+ lines)
- **Lines 112-130**: `getPendingApprovalCount()` reads from `registry` and `patient_registry` in localStorage
- **Lines 132-153**: `getUnpaidInvoicesCount()` scans payment-related keys
- **Lines 155-166**: `getPendingHandoverCount()` reads `handovers` from localStorage
- **Lines 168-203**: `getAdmittedPatientCount()` scans `patients_*` keys
- **Lines 97-110**: `loadBool/saveBool` helpers use localStorage directly

**Action:**
- Replace all localStorage reads with API calls:
  - `getPendingApprovalCount()` → `/api/auth/pending-approvals.php`
  - `getUnpaidInvoicesCount()` → `/api/invoices/unpaid.php`
  - `getAdmittedPatientCount()` → `/api/patients/admitted.php`
  - `getPendingHandoverCount()` → `/api/visits/handovers.php`
- UI preferences (sidebar state, theme) → keep in localStorage (allowed)

---

#### C. `hooks/useAdminSave.ts`
- **All functions**: `loadRegistry()`, `saveRegistry()`, `loadPatientRegistry()`, `savePatientRegistry()`, `appendAuditLog()`

**Action:**
- Replace with API calls:
  - `loadRegistry()` → `/api/staff/list.php`
  - `saveRegistry()` → `/api/staff/update.php`
  - `loadPatientRegistry()` → `/api/patients/list.php`
  - `savePatientRegistry()` → `/api/patients/update.php`
  - `appendAuditLog()` → `/api/audit/create.php`

---

#### D. `hooks/useEmailAuth.tsx` (CRITICAL)
- Handles user login/signup and session management
- Must use PHP authentication instead of client-side storage

**Action:**
- Replace localStorage session token storage with secure cookie-based auth
- Store ONLY `auth_token` if needed for API calls (all else in httpOnly cookies via PHP)
- Validate sessions via `/api/auth/verify.php`

---

### **MEDIUM PRIORITY** — Pages that read business data

These pages directly access localStorage for patient, appointment, prescription data:

- `pages/Patients.tsx` → Use React Query + `/api/patients/list.php`
- `pages/PatientProfile.tsx` → Use `/api/patients/get.php?id=X`
- `pages/Appointments.tsx` → Use `/api/appointments/list.php`
- `pages/Prescriptions.tsx` → Use `/api/prescriptions/list.php`
- `pages/Invoices.tsx` → Use `/api/invoices/list.php`
- `pages/Payments.tsx` → Use `/api/payments/list.php`
- `pages/Dashboard.tsx` → Aggregate from multiple API endpoints
- `pages/WardRound.tsx` → Use `/api/visits/list.php` + `/api/appointments/list.php`
- `pages/BedManagement.tsx` → Use `/api/beds/list.php` + `/api/admissions/list.php`
- `pages/VisitPage.tsx` → Use `/api/visits/create.php` and `/api/visits/list.php`
- All payment pages → Use `/api/invoices/`, `/api/payments/` endpoints

**Action:**
- Replace `JSON.parse(localStorage.getItem(...))` with React Query `useQuery()` calls
- Use existing `queryClient.invalidateQueries()` pattern to refresh data
- Maintain pagination/filtering in backend

---

### **LOW PRIORITY** — UI State (Keep in localStorage)

✅ These ARE allowed to stay in localStorage (UI preferences only):

```
// Allowed keys — NOT business data
- theme
- language
- sidebarState
- mobile_sidebar_expanded
- sidebar_hospital_group_open
- sidebar_payment_group_open
- auth_token (ONLY for API request headers, session validation via `/api/auth/verify.php`)
```

---

## 3. Step-by-Step Refactor Checklist

### Phase 1: Create API Wrapper Layer (DONE in deployed backend)
- [x] PHP endpoints already exist for all CRUD operations
- [x] Authentication via `/api/auth/login.php` and `/api/auth/verify.php`

### Phase 2: Update Authentication & Sessions
- [ ] Modify `useEmailAuth.tsx` to call `/api/auth/login.php` instead of saving to localStorage
- [ ] Move session validation to `/api/auth/verify.php` (server-side)
- [ ] Keep `auth_token` in localStorage only for API headers
- [ ] Remove all manual user registry/patient registry storage

### Phase 3: Update Admin/Data Management Hooks
- [ ] Replace `useAdminSave.ts` functions with API calls
- [ ] Update `App.tsx` approval panel to fetch from `/api/staff/pending.php`
- [ ] Update role reassignment to call `/api/staff/update.php`

### Phase 4: Update Layout & Dashboard
- [ ] Replace `getPendingApprovalCount()` → `/api/auth/pending-count.php`
- [ ] Replace `getUnpaidInvoicesCount()` → `/api/invoices/unpaid-count.php`
- [ ] Replace `getAdmittedPatientCount()` → `/api/patients/admitted-count.php`
- [ ] Keep sidebar state in localStorage (it's UI-only)

### Phase 5: Update Patient & Visit Pages
- [ ] Replace localStorage patient list with React Query `/api/patients/list.php`
- [ ] Replace visit data reads with `/api/visits/list.php`
- [ ] Update all create/update forms to POST to PHP endpoints
- [ ] Remove manual data persistence from form submissions

### Phase 6: Update Payment Pages
- [ ] Replace invoice data reads with `/api/invoices/list.php`
- [ ] Replace payment data reads with `/api/payments/list.php`
- [ ] Update payment form submissions to `/api/payments/create.php`

### Phase 7: Remove ICP/Blockchain Code
- [ ] Delete `canisterActors.tsx`
- [ ] Delete `declarations/` directory
- [ ] Delete `backend.d.ts`
- [ ] Remove `@dfinity/*`, `@icp-sdk/*`, `@caffeineai/*` from `package.json`
- [ ] Clean up any `tryCreateActor`, `resolveCanisterId` references

### Phase 8: Cleanup & Testing
- [ ] Run full frontend build: `npm run build`
- [ ] Test all pages for console errors
- [ ] Verify API calls in browser DevTools Network tab
- [ ] Test offline fallback (if needed)

---

## 4. API Endpoints to Create (if missing)

These endpoints may need to be added to the PHP backend:

```php
// Count endpoints (lightweight, for sidebar badges)
GET  /api/staff/pending-count.php           → { "count": N }
GET  /api/invoices/unpaid-count.php        → { "count": N }
GET  /api/patients/admitted-count.php      → { "count": N }
GET  /api/visits/handovers-pending.php     → { "count": N }

// Session/Auth validation (already exists)
GET  /api/auth/verify.php                  → { "user": {...}, "valid": true/false }

// Patient reminders (if not exists)
GET  /api/patients/{id}/reminders.php      → { "reminders": [...] }
POST /api/patients/{id}/reminders.php      → create reminder
PUT  /api/patients/{id}/reminders/{rid}.php → update reminder
```

---

## 5. Code Review Checklist

Before committing each phase:

- [ ] No `localStorage.` calls outside of `storageAdapter.ts` (allowed keys only)
- [ ] No `canister` references in code
- [ ] No `IndexedDB` calls
- [ ] All CRUD operations go through PHP API
- [ ] API errors handled gracefully (show toast, log audit trail)
- [ ] Network errors trigger reconnect UI (already in Layout.tsx)
- [ ] React Query cache invalidated after mutations
- [ ] No console errors in browser DevTools

---

## 6. Expected Outcome

✅ **Frontend:**
- Pure React UI → PHP API → MySQL database
- No business data stored locally
- All state from server (React Query cache)
- UI preferences only in localStorage
- No canister/blockchain code

✅ **Backend:**
- All CRUD operations handled by PHP API
- Database as single source of truth
- Session tokens issued & validated server-side
- Audit logs recorded on every action

✅ **User Experience:**
- Same functionality
- Better security (no sensitive data in browser storage)
- Scalable architecture (shared hosting → any cloud)
- Offline mode limited but safe (cached reads only)

---

## Notes

- **Migration Path:** Refactor one page at a time, test with real API calls
- **Rollback:** Keep localStorage-based code in git history
- **Performance:** React Query will cache data, reducing API calls
- **Testing:** Use `curl` to test PHP endpoints before integrating with frontend
- **Deployment:** After refactor, run `npm run build`, copy `dist/` to `public_html/`
