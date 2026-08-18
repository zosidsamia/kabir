# Phase 2: Authentication Refactor — useEmailAuth.tsx

**Goal:** Replace client-side user storage with server-side PHP authentication.

**Status:** Ready for Implementation

---

## Current State (BEFORE)

**File:** `source_build/dr.armankabir-main/src/frontend/src/hooks/useEmailAuth.tsx`

### What it does NOW (localStorage-based):
1. Stores user objects (`DoctorAccount`, `PatientAccount`) in localStorage
2. Stores user registry (`registry`, `patient_registry`) in localStorage on signup
3. On login, reads from localStorage to find & validate the user
4. On logout, clears localStorage
5. Manual session management with timestamps

### Problems:
- ❌ User data exposed in browser storage (security risk)
- ❌ No server-side validation of sessions
- ❌ Cannot scale to multi-device/multi-session
- ❌ Data duplication (frontend + backend PHP API)
- ❌ No audit trail

---

## Target State (AFTER)

### What it will do (PHP API-based):

1. **Login:** POST to `/api/auth/login.php`
   - Send: email, password
   - Receive: auth_token (JWT), user object
   - Store: auth_token in localStorage (for API headers only)
   - Store: user object in React state (memory, NOT localStorage)

2. **Session Verification:** GET `/api/auth/verify.php` on app load
   - Send: auth_token in header
   - Receive: current user object, valid/invalid status
   - Restore: user to React state if token valid

3. **Logout:** POST `/api/auth/logout.php`
   - Send: auth_token
   - Clear: localStorage auth_token
   - Clear: React state

4. **Signup:** POST `/api/auth/signup.php` (staff/doctor only)
   - Send: full_name, email, password, role, designation, etc.
   - Receive: success message + status (pending approval)
   - Status updates via polling `/api/auth/pending-approvals.php`

---

## Step-by-Step Implementation

### Step 1: Create new API service layer

**File:** `src/services/authAPI.ts` (NEW)

```typescript
// Helper to add auth token to request headers
function getAuthHeaders(): Record<string, string> {
  const token = localStorage.getItem('auth_token');
  return {
    'Content-Type': 'application/json',
    ...(token ? { 'Authorization': `Bearer ${token}` } : {}),
  };
}

// Login endpoint
export async function loginDoctor(email: string, password: string) {
  const response = await fetch('/api/auth/login.php', {
    method: 'POST',
    headers: getAuthHeaders(),
    body: JSON.stringify({ email, password, role: 'doctor' }),
  });
  
  if (!response.ok) {
    const err = await response.json();
    throw new Error(err.message || 'Login failed');
  }
  
  const data = await response.json();
  // Store only the token, not the user (user goes to React state)
  if (data.auth_token) {
    localStorage.setItem('auth_token', data.auth_token);
  }
  return data; // { auth_token, user: { id, email, name, role, ... } }
}

// Similar for patientLogin, signup, logout
export async function loginPatient(phone: string, password: string) { ... }
export async function signupDoctor(payload: DoctorSignupPayload) { ... }
export async function signupPatient(payload: PatientSignupPayload) { ... }
export async function logout(token: string) { ... }
export async function verifySession() { ... }
```

---

### Step 2: Update useEmailAuth.tsx

**Critical changes:**

#### A. Remove localStorage for user data
```typescript
// REMOVE:
// storage.setItem('registry', JSON.stringify([...]))
// storage.getItem('registry')
// storage.setItem('patient_registry', JSON.stringify([...]))
// storage.getItem('patient_registry')

// KEEP (UI preferences only):
// localStorage.getItem('auth_token')  ← for API headers
// localStorage.getItem('theme')       ← UI preference
// localStorage.getItem('language')    ← UI preference
```

#### B. Replace login logic
```typescript
// BEFORE:
async function signIn(email: string, password: string) {
  const reg = loadRegistry();
  const user = reg.find(u => u.email === email && verifyPassword(u, password));
  if (user) {
    setCurrentDoctor(user);
    setIsLoggedIn(true);
  }
}

// AFTER:
async function signIn(email: string, password: string) {
  try {
    const result = await loginDoctor(email, password);
    setCurrentDoctor(result.user);  // Store user in React state (memory)
    setIsLoggedIn(true);
    return true;
  } catch (err) {
    setAuthError(err.message);
    return false;
  }
}
```

#### C. Replace signup logic
```typescript
// AFTER:
async function signUp(payload: DoctorSignupPayload) {
  try {
    const result = await signupDoctor(payload);
    // Result will have status like "pending" or "approved"
    if (result.status === 'pending') {
      setAuthError('Account created. Awaiting admin approval.');
    } else {
      // Auto-approve flow (if enabled)
      const loginResult = await loginDoctor(payload.email, payload.password);
      setCurrentDoctor(loginResult.user);
    }
  } catch (err) {
    throw new Error(err.message || 'Signup failed');
  }
}
```

#### D. Session verification on app load
```typescript
// On component mount:
useEffect(() => {
  const token = localStorage.getItem('auth_token');
  if (!token) {
    setIsInitializing(false);
    return;
  }
  
  // Verify with server
  verifySession()
    .then(result => {
      if (result.valid) {
        setCurrentDoctor(result.user);
      } else {
        localStorage.removeItem('auth_token');
      }
    })
    .catch(() => {
      localStorage.removeItem('auth_token');
    })
    .finally(() => setIsInitializing(false));
}, []);
```

#### E. Logout
```typescript
async function signOut() {
  try {
    const token = localStorage.getItem('auth_token');
    if (token) {
      await logout(token);
    }
  } finally {
    localStorage.removeItem('auth_token');
    setCurrentDoctor(null);
    setIsLoggedIn(false);
  }
}
```

---

### Step 3: Update PHP backend endpoints (if missing)

Check these exist in `public_html/api/auth/`:

#### `login.php` (MUST EXIST)
```php
<?php
// POST /api/auth/login.php
// Expect: { email, password, role }
// Return: { auth_token, user: { id, email, name, role, ... } }

$email = $_POST['email'] ?? $_REQUEST['email'] ?? '';
$password = $_POST['password'] ?? $_REQUEST['password'] ?? '';
$role = $_POST['role'] ?? $_REQUEST['role'] ?? 'doctor';

// Query database for user
// Verify password with bcrypt
// Generate JWT token

// Return success or 401 Unauthorized
```

#### `verify.php` (MUST EXIST)
```php
<?php
// GET /api/auth/verify.php
// Header: Authorization: Bearer {token}
// Return: { valid: true/false, user: {...} }

// Extract token from header
// Verify JWT signature
// Return current user if valid
```

#### `logout.php` (MUST EXIST)
```php
<?php
// POST /api/auth/logout.php
// Header: Authorization: Bearer {token}
// Return: { success: true }

// Invalidate token (if using token blacklist)
// Or just accept and return success (stateless JWT)
```

#### `signup.php` (MUST EXIST)
```php
<?php
// POST /api/auth/signup.php
// Expect: { full_name, email, password, role, designation, ... }
// Return: { status: 'pending' | 'approved', message, user?: {...} }

// Create user in database
// Set status to 'pending' (requires admin approval)
// Or auto-approve based on role
// Return user object if approved
```

---

### Step 4: Testing Checklist

- [ ] Login with valid credentials → receives auth_token and user object
- [ ] Login with invalid credentials → error message
- [ ] auth_token stored in localStorage (DevTools Storage tab)
- [ ] User object NOT in localStorage (only in memory/React state)
- [ ] Refresh page → session restored via `/api/auth/verify.php`
- [ ] Logout → auth_token removed from localStorage
- [ ] Signup doctor → status pending or approved
- [ ] Signup patient → status pending or approved
- [ ] Network tab shows POST /api/auth/login.php, GET /api/auth/verify.php
- [ ] No console errors related to localStorage user data

---

### Step 5: Migrate Data (One-Time)

**When deploying Phase 2:**

1. Staff/Doctors must re-login
   - Their accounts are in MySQL database (from PHP backend)
   - localStorage registry will be ignored
   
2. Patients must re-login
   - Their accounts are in MySQL database
   - localStorage patient_registry will be ignored

3. Admin accounts
   - Already in PHP (config or database)
   - Use `/api/auth/admin-login.php` if separate endpoint

---

## Files to Modify

1. **`src/hooks/useEmailAuth.tsx`** — Replace with API calls
2. **`src/services/authAPI.ts`** — NEW, API wrapper
3. **`src/App.tsx`** — Already uses useEmailAuth, no changes needed
4. **`src/Layout.tsx`** — Already uses useEmailAuth, no changes needed
5. **`public_html/api/auth/login.php`** — Verify exists
6. **`public_html/api/auth/verify.php`** — Verify exists
7. **`public_html/api/auth/logout.php`** — Verify exists
8. **`public_html/api/auth/signup.php`** — Verify exists

---

## Expected Outcome

✅ **After Phase 2:**
- Authentication fully server-side (PHP + MySQL)
- Sessions validated via JWT tokens
- No user data in localStorage
- auth_token only in localStorage (for API headers)
- Ready to refactor other hooks (useAdminSave, etc.)

---

## Rollback Plan

If issues arise:
1. Keep old `useEmailAuth.tsx` in git history
2. Restore localStorage-based version temporarily
3. Debug API endpoints
4. Re-apply refactor

---

## Next: Phase 3

After Phase 2 is complete and tested:
- Refactor `hooks/useAdminSave.ts` (admin data management)
- Refactor `hooks/useRolePermissions.ts` (role-based access)
- Update `App.tsx` pending approvals panel

