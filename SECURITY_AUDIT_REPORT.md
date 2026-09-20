# Security Audit Report - SDPG College ERP

**Date:** September 14, 2026  
**Auditor:** Security Analysis  
**Scope:** Full API security review of authentication, authorization, input validation, and data handling

## Executive Summary

This security audit identified **12 critical and high-severity vulnerabilities** across the SDPG College ERP system. The most critical issues involve production security leaks in password reset functionality, missing rate limiting on authentication endpoints, and insufficient authorization controls in several API endpoints.

### Risk Summary
- **Critical Vulnerabilities:** 3
- **High Severity:** 5  
- **Medium Severity:** 3
- **Low Severity:** 1

---

## Critical Vulnerabilities

### 1. **Production Security Leak in Password Reset (CRITICAL)**

**Location:** `app/Http/Controllers/Api/AuthController.php:229-261`

**Issue:** The `forgotPassword()` and `studentForgotPassword()` methods return sensitive reset URLs and temporary passwords directly in API responses.

```php
// Lines 251-260 in AuthController.php
$resetUrl = config('app.frontend_url', 'http://localhost:3000')
          . "/reset-password?token={$token}&email=" . urlencode($user->email);

return response()->json([
    'message'    => 'Password reset link sent to your email.',
    'reset_url'  => $resetUrl,  // ⚠️ SECURITY LEAK - Remove in production
]);
```

```php
// Lines 335-339 in AuthController.php  
return response()->json([
    'message'       => 'A temporary password has been generated.',
    'temp_password' => $tempPassword,  // ⚠️ SECURITY LEAK - Remove in production
]);
```

**Impact:** Attackers can intercept API responses to obtain password reset tokens and temporary passwords, allowing complete account takeover.

**Recommendation:**
- Remove `reset_url` and `temp_password` from API responses
- Implement proper email/SMS delivery for password resets
- Add environment check to ensure these are never returned in production

### 2. **Missing Rate Limiting on Authentication Endpoints (CRITICAL)**

**Location:** `routes/api.php:34-44` (Authentication endpoints)

**Issue:** No rate limiting implemented on authentication-related endpoints:
- `/auth/login`
- `/auth/student/login` 
- `/auth/forgot-password`
- `/auth/student/forgot-password`
- `/student/register/otp/*` (OTP endpoints)

**Impact:** Enables brute force attacks on passwords, OTP enumeration attacks, and denial of service through excessive authentication attempts.

**Recommendation:**
```php
// Add rate limiting to authentication routes
Route::middleware('throttle:5,1')->group(function () {
    Route::post('login', [AuthController::class, 'login']);
    Route::post('student/login', [AuthController::class, 'studentLogin']);
    Route::post('forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('student/forgot-password', [AuthController::class, 'studentForgotPassword']);
});

// Stricter rate limiting for OTP endpoints
Route::middleware('throttle:3,1')->prefix('student/register')->group(function () {
    Route::post('otp/phone/send', [StudentRegistrationController::class, 'preSendPhoneOtp']);
    Route::post('otp/email/send', [StudentRegistrationController::class, 'preSendEmailOtp']);
});
```

### 3. **Insufficient Authorization on Student Data Access (CRITICAL)**

**Location:** `app/Http/Controllers/Api/StudentController.php:45-54`

**Issue:** The `show()` method uses route model binding without proper authorization checks, allowing any authenticated college portal user to access any student's data.

```php
public function show(Student $student): JsonResponse
{
    $student->load([
        'currentAdmission.program',
        'documents',
        'feeReceipts' => fn($q) => $q->latest()->take(5),
    ]);
    return response()->json($student);
}
```

**Impact:** Unauthorized access to sensitive student data including documents, fee receipts, and academic information.

**Recommendation:**
```php
public function show(Student $student): JsonResponse
{
    // Add authorization check
    if ($student->organization_id !== $request->user()->organization_id) {
        return response()->json(['message' => 'Unauthorized'], 403);
    }
    
    $student->load([
        'currentAdmission.program',
        'documents',
        'feeReceipts' => fn($q) => $q->latest()->take(5),
    ]);
    return response()->json($student);
}
```

---

## High Severity Vulnerabilities

### 4. **Token Expiration Not Configured (HIGH)**

**Location:** `config/sanctum.php:53`

**Issue:** Sanctum token expiration is set to `null`, meaning tokens never expire.

```php
'expiration' => null,  // ⚠️ Tokens never expire
```

**Impact:** Compromised tokens remain valid indefinitely, increasing the window of opportunity for attackers.

**Recommendation:**
```php
'expiration' => env('SANCTUM_EXPIRATION', 60), // 60 minutes
```

### 5. **Missing CSRF Protection (HIGH)**

**Location:** `routes/api.php` (No CSRF middleware applied)

**Issue:** API routes don't have CSRF protection enabled, making them vulnerable to cross-site request forgery attacks.

**Impact:** Attackers can trick authenticated users into performing unwanted actions on the web application.

**Recommendation:**
- Ensure stateful domains are properly configured in `config/sanctum.php`
- Consider implementing CSRF tokens for state-changing operations
- Use SameSite cookie attributes as additional protection

### 6. **File Upload Security Weaknesses (HIGH)**

**Location:** Multiple file upload endpoints:
- `app/Http/Controllers/Api/StudentController.php:219-231` (Photo upload)
- `app/Http/Controllers/Api/StudentController.php:233-245` (Signature upload)
- `app/Http/Controllers/Api/OrganizationController.php:45-57` (Logo upload)

**Issue:** File validation only checks file extensions and MIME types, not actual file content:

```php
$request->validate(['photo' => 'required|image|mimes:jpg,jpeg,png|max:500']);
```

**Impact:** Attackers can upload malicious files with valid extensions but harmful content (e.g., PHP files renamed to .jpg, XSS payloads in images).

**Recommendation:**
```php
// Add content validation
$validated = $request->validate([
    'photo' => 'required|image|mimes:jpg,jpeg,png|max:500',
]);

// Additional file content validation
$file = $request->file('photo');
$allowedMimeTypes = ['image/jpeg', 'image/png', 'image/jpg'];
if (!in_array($file->getMimeType(), $allowedMimeTypes)) {
    return response()->json(['message' => 'Invalid file type'], 422);
}

// Scan for malicious content
if ($this->containsMaliciousContent($file)) {
    return response()->json(['message' => 'File contains malicious content'], 422);
}
```

### 7. **SQL Injection Risk in Raw Queries (HIGH)**

**Location:** Several `whereRaw` usages throughout controllers:
- `app/Http/Controllers/Api/AuthController.php:52,233`
- `app/Http/Controllers/Api/AdmissionController.php:114`
- `app/Http/Controllers/Api/RegistrationController.php:177-178`

**Issue:** While most use parameterized queries, some raw SQL queries could be vulnerable if not properly handled:

```php
// AdmissionController.php:114
->whereRaw('semester_no < (SELECT total_semesters FROM programs WHERE id = admissions.program_id)')
```

**Impact:** Potential SQL injection if user input is not properly sanitized in raw queries.

**Recommendation:**
- Audit all `whereRaw` usages
- Ensure all user input is properly parameterized
- Consider using query builder methods instead of raw SQL where possible

### 8. **Weak Password Requirements (HIGH)**

**Location:** `app/Http/Controllers/Api/AuthController.php:271,351`

**Issue:** Password validation only requires minimum length without complexity requirements:

```php
'password' => 'required|string|min:8|confirmed',
```

**Impact:** Users can set weak passwords that are susceptible to brute force and dictionary attacks.

**Recommendation:**
```php
'password' => 'required|string|min:8|confirmed|regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&])[A-Za-z\d@$!%*?&]/',
```

---

## Medium Severity Vulnerabilities

### 9. **Sensitive Data in Audit Logs (MEDIUM)**

**Location:** `app/Http/Middleware/AuditMiddleware.php:27`

**Issue:** Audit middleware logs request data that may contain sensitive information:

```php
'new_values' => $request->except(['password', 'password_confirmation', 'current_password']),
```

**Impact:** While passwords are excluded, other sensitive data may be logged in plaintext.

**Recommendation:**
- Expand the exclusion list to include other sensitive fields
- Implement data masking for PII in logs
- Consider encrypting audit log data

### 10. **Missing CORS Configuration (MEDIUM)**

**Location:** No CORS configuration file found

**Issue:** Missing or incomplete CORS configuration could allow unauthorized cross-origin requests.

**Impact:** Potential for cross-origin attacks if not properly configured.

**Recommendation:**
- Create `config/cors.php` with proper CORS configuration
- Restrict allowed origins to specific domains
- Implement proper CORS headers

### 11. **Frontend Token Storage in localStorage (MEDIUM)**

**Location:** `src/lib/auth.ts:29-30,36-37`

**Issue:** Authentication tokens stored in localStorage are vulnerable to XSS attacks:

```typescript
localStorage.setItem('erp_token', data.token);
localStorage.setItem('erp_user', JSON.stringify(data.user));
```

**Impact:** XSS vulnerabilities could allow attackers to steal authentication tokens.

**Recommendation:**
- Consider using httpOnly cookies for token storage
- Implement additional security measures like token rotation
- Add XSS protection headers

---

## Low Severity Vulnerabilities

### 12. **Verbose Error Messages (LOW)**

**Location:** Various error responses throughout the application

**Issue:** Some error messages reveal too much information about system internals.

**Impact:** Could aid attackers in reconnaissance efforts.

**Recommendation:**
- Implement generic error messages for production
- Log detailed errors server-side
- Use environment-specific error handling

---

## Positive Security Findings

### Strengths Identified:

1. **Comprehensive Input Validation:** The `RegistrationInputGuard` class provides excellent validation for registration data including Aadhaar verification using Verhoeff algorithm.

2. **Portal-based Access Control:** Good implementation of portal middleware to separate college and student access.

3. **Audit Logging:** Comprehensive audit middleware tracks important state changes.

4. **Parameterized Queries:** Most database queries use proper parameterization to prevent SQL injection.

5. **Authentication Framework:** Proper use of Laravel Sanctum for token-based authentication.

---

## Recommendations Summary

### Immediate Actions (Critical):
1. Remove sensitive data from password reset API responses
2. Implement rate limiting on all authentication endpoints
3. Add authorization checks to student data access endpoints

### Short-term Actions (High):
4. Configure token expiration in Sanctum
5. Implement CSRF protection
6. Enhance file upload validation
7. Audit and secure raw SQL queries
8. Strengthen password requirements

### Medium-term Actions (Medium):
9. Improve audit log data handling
10. Configure CORS properly
11. Evaluate token storage strategy

### Long-term Actions (Low):
12. Implement generic error messages
13. Conduct regular security audits
14. Implement security headers (CSP, X-Frame-Options, etc.)

---

## Testing Recommendations

1. **Penetration Testing:** Conduct regular penetration testing focusing on authentication and authorization
2. **Dependency Scanning:** Implement automated dependency vulnerability scanning
3. **Code Review:** Establish security code review process for all changes
4. **Monitoring:** Implement security monitoring and alerting for suspicious activities

---

## Compliance Considerations

The identified vulnerabilities may impact compliance with:
- **Data Protection Laws:** Unauthorized access to student PII
- **Educational Regulations:** Security standards for student data
- **Payment Security:** Weaknesses in fee handling and receipt generation

---

## Conclusion

The SDPG College ERP system has a solid foundation but requires immediate attention to critical security vulnerabilities, particularly around authentication and authorization. The priority should be addressing the production security leaks in password reset functionality and implementing proper rate limiting to prevent brute force attacks.

**Overall Security Rating:** **6/10** (Needs Improvement)

**Estimated Remediation Time:** 2-3 weeks for critical and high-priority issues.