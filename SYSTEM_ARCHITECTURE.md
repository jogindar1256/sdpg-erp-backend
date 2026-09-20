# SDPG College ERP - System Architecture Diagram

## Overview
The SDPG College ERP is a comprehensive college management system built with a modern frontend (Next.js) and robust backend (Laravel), supporting both college staff and student portals.

## High-Level Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│                        CLIENT SIDE                                │
├─────────────────────────────────────────────────────────────────┤
│                                                                   │
│  ┌──────────────────┐      ┌──────────────────┐                │
│  │  College Portal  │      │  Student Portal  │                │
│  │  (Staff/Admin)   │      │  (Students)      │                │
│  └────────┬─────────┘      └────────┬─────────┘                │
│           │                          │                          │
│           └──────────┬───────────────┘                          │
│                      │                                          │
│              ┌───────▼────────┐                                 │
│              │  Next.js 16.2.7 │                                 │
│              │  React 19.2.4   │                                 │
│              │  TypeScript     │                                 │
│              │  Tailwind CSS   │                                 │
│              └───────┬────────┘                                 │
│                      │                                          │
│              ┌───────▼────────┐                                 │
│              │  Axios Client  │                                 │
│              │  (API Calls)   │                                 │
│              └───────┬────────┘                                 │
└──────────────────────┼──────────────────────────────────────────┘
                       │ HTTP/REST API
                       │ Bearer Token Auth
┌──────────────────────┼──────────────────────────────────────────┐
│              ┌───────▼────────┐                                 │
│              │  Laravel 12.0  │                                 │
│              │  PHP 8.2+      │                                 │
│              │  API Routes    │                                 │
│              └───────┬────────┘                                 │
│                      │                                          │
│  ┌───────────────────┼───────────────────┐                      │
│  │                   │                   │                      │
│  ▼                   ▼                   ▼                      │
│ ┌─────────┐    ┌──────────┐      ┌─────────────┐               │
│ │Controllers│  │  Models  │      │  Services   │               │
│ │(25+ API)│  │(Eloquent)│      │(SMS, PDF,   │               │
│ └─────────┘    └──────────┘      │Admission,  │               │
│                                  │etc.)       │               │
│                                  └─────────────┘               │
│                      │                                          │
│              ┌───────▼────────┐                                 │
│              │  PostgreSQL    │                                 │
│              │  Database      │                                 │
│              └───────┬────────┘                                 │
│                      │                                          │
│              ┌───────▼────────┐                                 │
│              │  Redis         │                                 │
│              │  (Cache/Queue) │                                 │
│              └────────────────┘                                 │
└─────────────────────────────────────────────────────────────────┘
```

## Frontend Architecture (Next.js)

### Directory Structure
```
src/
├── app/
│   ├── layout.tsx              # Root layout with Providers
│   ├── page.tsx               # Redirects to /login
│   ├── login/                 # College staff login
│   ├── college/               # College portal routes
│   │   ├── layout.tsx         # College layout (Sidebar + Header)
│   │   ├── admissions/        # Admission management
│   │   ├── applications/      # Application processing
│   │   ├── examination/      # Exam management
│   │   ├── fees/              # Fee management
│   │   ├── certificates/     # Certificate generation
│   │   ├── amendments/       # Student amendments
│   │   ├── authorization/    # Authorization workflows
│   │   └── dashboard/        # College dashboard
│   └── student/               # Student portal routes
│       ├── layout.tsx         # Student layout (Sidebar only)
│       ├── login/             # Student login
│       ├── register/          # Student registration
│       ├── dashboard/         # Student dashboard
│       └── applications/      # Student applications
├── components/
│   ├── Providers.tsx          # React Query + Toast providers
│   ├── layout/                # Layout components (Sidebar, Header)
│   ├── ui/                    # Reusable UI components
│   └── shared/                # Shared components
├── hooks/
│   └── useAuth.ts             # Authentication hook (Zustand)
├── lib/
│   ├── api.ts                 # Axios instance with interceptors
│   ├── auth.ts                # Auth functions (login, logout)
│   └── utils.ts               # Utility functions
└── types/                     # TypeScript type definitions
```

### Key Frontend Components

#### 1. Authentication System
- **Zustand Store** (`useAuthStore`): Manages user state
- **Auth Hook** (`useAuth`): Provides auth functions and route protection
- **Token Management**: Stores `erp_token` and `erp_user` in localStorage
- **Portal-based Routing**: Separate layouts for college vs student portals

#### 2. API Client
- **Axios Instance**: Configured with `NEXT_PUBLIC_API_URL`
- **Request Interceptor**: Attaches Bearer token to all requests
- **Response Interceptor**: Handles 401 errors and redirects to login

#### 3. State Management
- **React Query**: Server state management, caching, and data fetching
- **Zustand**: Client state management for authentication
- **Local Storage**: Token and user data persistence

#### 4. UI Components
- **Radix UI**: Headless UI components
- **Shadcn**: Styled component library
- **Lucide React**: Icon library
- **Sonner**: Toast notifications

## Backend Architecture (Laravel)

### Directory Structure
```
app/
├── Http/
│   ├── Controllers/
│   │   └── Api/               # API Controllers (25+)
│   │       ├── AuthController.php
│   │       ├── StudentRegistrationController.php
│   │       ├── ApplicationController.php
│   │       ├── AdmissionController.php
│   │       ├── ExaminationController.php
│   │       ├── FeesController.php
│   │       ├── AmendmentController.php
│   │       ├── CertificateController.php
│   │       └── ... (20+ more)
│   ├── Middleware/
│   │   ├── AuditMiddleware.php
│   │   └── PortalMiddleware.php
│   └── Concerns/
│       ├── LocksStudentIdentity.php
│       └── ResolvesStudentIdentity.php
├── Models/                    # Eloquent Models (40+)
│   ├── User.php
│   ├── Student.php
│   ├── Admission.php
│   ├── StudentApplication.php
│   ├── FeeReceipt.php
│   ├── Examination.php
│   ├── Certificate.php
│   └── ... (35+ more)
├── Services/
│   ├── SmsService.php         # SMS gateway integration
│   └── AdmissionNumberService.php
├── Jobs/
│   └── GenerateFeeReceiptPdf.php
├── Support/
│   ├── RegistrationInputGuard.php
│   └── TextNormalizer.php
└── Providers/
    └── AppServiceProvider.php
```

### Key Backend Components

#### 1. API Routes Structure
```
/api/
├── auth/                      # Public authentication
│   ├── login                 # College staff login
│   ├── student/login         # Student login
│   ├── forgot-password       # Password reset
│   └── reset-password
├── student/register/          # Public student registration
│   ├── courses               # Available courses
│   ├── subjects              # Program subjects
│   ├── otp/phone/send        # Phone OTP verification
│   ├── otp/email/send        # Email OTP verification
│   ├── init                  # Create registration draft
│   ├── payment/initiate      # Payment initiation
│   └── payment/verify        # Payment verification
├── admissions/               # Protected: Admission management
├── applications/             # Protected: Application processing
├── examination/              # Protected: Exam management
├── fees/                     # Protected: Fee management
├── certificates/             # Protected: Certificate generation
├── amendments/               # Protected: Student amendments
└── authorization/            # Protected: Authorization workflows
```

#### 2. Database Schema (Key Tables)
- **users**: System users (staff, students, admins)
- **students**: Student profiles and academic data
- **organizations**: Multi-tenant organization support
- **programs**: Academic programs (BA, BSc, MA, etc.)
- **subjects**: Course subjects and papers
- **student_applications**: Student admission applications
- **direct_registrations**: Direct student registrations
- **admissions**: Student admissions with status tracking
- **fee_structures**: Fee configuration by program/semester
- **fee_receipts**: Fee payment receipts
- **examinations**: Exam schedules and results
- **certificates**: Generated certificates
- **amendments**: Student record amendments
- **sms_templates**: SMS message templates
- **audit_logs**: System audit trail

#### 3. Authentication & Authorization
- **Laravel Sanctum**: API token authentication
- **Spatie Permissions**: Role-based access control
- **Portal Middleware**: Route protection by portal type
- **Audit Middleware**: Action logging for compliance

#### 4. Key Services
- **SmsService**: Integration with Infibrix SMS gateway
- **AdmissionNumberService**: Generates admission identifiers
- **PDF Generation**: DomPDF and Snappy for document generation

## Data Flow Diagrams

### 1. College Staff Authentication Flow
```
┌─────────────┐    ┌─────────────┐    ┌─────────────┐    ┌─────────────┐
│   Login     │───▶│   Next.js   │───▶│   Laravel   │───▶│  Database   │
│   Form      │    │   Client    │    │   API       │    │  (Users)    │
└─────────────┘    └─────────────┘    └─────────────┘    └─────────────┘
                       │                   │
                       │                   │
                       ▼                   ▼
              ┌─────────────┐    ┌─────────────┐
              │ Store Token │    │ Generate    │
              │ in localStorage│ │ Sanctum Token│
              └─────────────┘    └─────────────┘
```

### 2. Student Registration Flow
```
┌─────────────┐    ┌─────────────┐    ┌─────────────┐    ┌─────────────┐
│   Student   │───▶│   Next.js   │───▶│   Laravel   │───▶│  Database   │
│  Register   │    │   Form      │    │   API       │    │  (Students) │
└─────────────┘    └─────────────┘    └─────────────┘    └─────────────┘
                       │                   │
                       │                   │
                       ▼                   ▼
              ┌─────────────┐    ┌─────────────┐
              │   Send OTP  │    │  Verify OTP │
              │  (SMS/Email)│    │  (Cache)    │
              └─────────────┘    └─────────────┘
                       │                   │
                       ▼                   ▼
              ┌─────────────┐    ┌─────────────┐
              │  Payment    │    │  Create     │
              │  Gateway    │    │  Student    │
              └─────────────┘    └─────────────┘
```

### 3. Exam Management Flow
```
┌─────────────┐    ┌─────────────┐    ┌─────────────┐    ┌─────────────┐
│   College   │───▶│   Next.js   │───▶│   Laravel   │───▶│  Database   │
│   Staff     │    │   Dashboard │    │   API       │    │(Examinations)│
└─────────────┘    └─────────────┘    └─────────────┘    └─────────────┘
                       │                   │
                       │                   │
                       ▼                   ▼
              ┌─────────────┐    ┌─────────────┐
              │  Schedule   │    │  Generate   │
              │   Exams     │    │  PDFs       │
              └─────────────┘    └─────────────┘
```

## Technology Stack

### Frontend
- **Framework**: Next.js 16.2.7 (App Router)
- **Language**: TypeScript
- **UI Library**: React 19.2.4
- **Styling**: Tailwind CSS 4
- **State Management**: Zustand 5.0.14, TanStack Query 5.101.0
- **HTTP Client**: Axios 1.17.0
- **Forms**: React Hook Form 7.77.0
- **Validation**: Zod 4.4.3
- **UI Components**: Radix UI, Shadcn
- **Icons**: Lucide React
- **Notifications**: Sonner

### Backend
- **Framework**: Laravel 12.0
- **Language**: PHP 8.2+
- **Database**: PostgreSQL
- **Cache/Queue**: Redis
- **Authentication**: Laravel Sanctum
- **Authorization**: Spatie Laravel Permission
- **PDF Generation**: DomPDF, Snappy
- **Excel**: Maatwebsite Excel
- **File Storage**: AWS S3 (via Flysystem)
- **SMS Gateway**: Infibrix Technologies

## Key Features

### College Portal Features
- **Admission Management**: Process and manage student admissions
- **Application Processing**: Handle student applications with workflow
- **Examination Management**: Schedule exams, manage seating, generate documents
- **Fee Management**: Configure fee structures, generate receipts
- **Certificate Generation**: Generate various student certificates
- **Amendments**: Handle student record modifications
- **Authorization**: Multi-level approval workflows
- **Dashboard**: Overview and statistics

### Student Portal Features
- **Registration**: Self-registration with OTP verification
- **Application Submission**: Apply for programs with document upload
- **Payment Integration**: Online fee payment
- **Dashboard**: View academic progress, fees, exam schedules
- **Document Management**: Access certificates and documents

## Security Features
- **Token-based Authentication**: Sanctum tokens with expiration
- **Role-based Access Control**: Granular permissions system
- **Portal Separation**: College and student portals are isolated
- **Audit Logging**: All actions logged for compliance
- **Input Validation**: Comprehensive validation on both frontend and backend
- **SQL Injection Protection**: Eloquent ORM with parameterized queries
- **CSRF Protection**: Built-in Laravel CSRF protection
- **Rate Limiting**: API rate limiting for abuse prevention

## Deployment Architecture
```
┌─────────────────────────────────────────────────────────────┐
│                      Production                              │
├─────────────────────────────────────────────────────────────┤
│  ┌──────────────┐         ┌──────────────┐                 │
│  │  Next.js     │         │  Laravel     │                 │
│  │  (Frontend)  │         │  (Backend)   │                 │
│  │  Port: 3000  │         │  Port: 8000  │                 │
│  └──────────────┘         └──────────────┘                 │
│         │                         │                          │
│         └──────────┬──────────────┘                          │
│                    │                                         │
│            ┌───────▼────────┐                               │
│            │  Load Balancer │                               │
│            └───────┬────────┘                               │
│                    │                                         │
│    ┌───────────────┼───────────────┐                        │
│    │               │               │                        │
│    ▼               ▼               ▼                        │
│ ┌────────┐   ┌────────┐   ┌────────┐                      │
│ │PostgreSQL│  │  Redis │  │ AWS S3 │                      │
│ │Database │  │  Cache │  │ Storage│                      │
│ └────────┘   └────────┘   └────────┘                      │
└─────────────────────────────────────────────────────────────┘
```

## Key Integration Points

### 1. Frontend-Backend Communication
- **Protocol**: HTTP/REST
- **Authentication**: Bearer tokens in Authorization header
- **Content Type**: application/json
- **Error Handling**: Standardized JSON error responses

### 2. External Services
- **SMS Gateway**: Infibrix Technologies (OTP and notifications)
- **Payment Gateway**: Razorpay (integrated for fee payments)
- **Email Service**: SMTP/Postmark/Resend/SES
- **File Storage**: AWS S3 for document storage

### 3. Database Relationships
- **User → Student**: One-to-one relationship
- **Student → Applications**: One-to-many
- **Student → Admissions**: One-to-many
- **Student → FeeReceipts**: One-to-many
- **Organization → Users**: One-to-many
- **Program → Subjects**: One-to-many
- **Program → FeeStructures**: One-to-many

## Performance Optimization
- **Frontend**: React Query caching, code splitting, image optimization
- **Backend**: Redis caching, database indexing, queue processing
- **Database**: Connection pooling, query optimization, proper indexing

## Monitoring & Logging
- **Application Logs**: Laravel Pail for real-time log monitoring
- **Audit Trail**: Comprehensive audit logging for all user actions
- **Error Tracking**: Structured error logging and reporting
- **Performance Monitoring**: Query performance tracking

This architecture provides a scalable, maintainable, and secure foundation for the SDPG College ERP system, supporting both college staff and student workflows with comprehensive functionality for academic management.