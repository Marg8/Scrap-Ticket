# Scrap-Ticket System

A PHP + MySQL web application for creating and managing **scrap material tickets** with a multi-level DOA (Delegation of Authority) approval workflow.

---

## Features

- Create scrap tickets with: **BU, Line, Part Number, Qty, Unit Cost, Amount (auto-calculated)**
- Multi-level **DOA approval chain** based on ticket amount
- Approval / Rejection with approver name, role and comments
- Real-time ticket status: `Pending → Partially Approved → Approved / Rejected`
- Ticket list with search and status filter

---

## Requirements

- PHP 7.4+ with PDO and PDO_MySQL extensions
- MySQL 5.7+ (or MariaDB 10.3+)
- A web server (Apache, Nginx, or PHP built-in server)

---

## Setup

### 1. Clone the repository
```bash
git clone https://github.com/Marg8/Scrap-Ticket.git
cd Scrap-Ticket
```

### 2. Configure the database
Edit `config.php` and set your MySQL credentials:
```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'scrap_tickets_db');
define('DB_USER', 'your_user');
define('DB_PASS', 'your_password');
```

### 3. Run database setup
Visit `http://your-server/setup.php` in a browser, **or** run:
```bash
php setup.php
```
This creates the database, tables, and seeds the default DOA levels.

### 4. Start using the app
Open `http://your-server/index.php` (or `http://localhost:8000` if using the PHP built-in server).

```bash
# Quick start with PHP built-in server
php -S localhost:8000
```

---

## DOA Approval Levels (default)

| Level | Approver Role      | Amount Range (USD)      |
|-------|--------------------|-------------------------|
| 1     | Supervisor         | $0.00 – $500.00         |
| 2     | Manager            | $500.01 – $2,000.00     |
| 3     | Director           | $2,000.01 – $10,000.00  |
| 4     | VP / Plant Manager | $10,000.01 and above    |

> All levels whose **minimum amount** is <= the ticket amount will be required to approve.
> Approvals are processed **sequentially** (Level 1 must approve before Level 2 can act).

---

## File Structure

```
Scrap-Ticket/
├── config.php          # Database configuration
├── db.php              # PDO connection helper
├── setup.php           # One-time database setup script
├── index.php           # Ticket list (home page)
├── create_ticket.php   # Create new scrap ticket
├── view_ticket.php     # View ticket details + approval chain
├── approve_ticket.php  # Handle approve/reject POST actions
└── assets/
    └── css/
        └── style.css   # Application stylesheet
```
