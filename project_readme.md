# NSBM Campus Event & Smart Scheduling Hub

A full-stack web-based application developed for the **NSBM Green University Faculty of Computing** Web and Mobile Application Development module. This system customizes and expands the traditional SportsHub concept into a comprehensive **Campus Event Management & Smart Timetable Scheduling Platform**, designed to eliminate event date clashes across university batches.

---

## 🚀 Key Features

### 1. 🎓 Student Portal
* **Event Discovery:** Browse approved campus events, workshops, sports tournaments, and club activities.
* **Target Audience Filtering:** View events targeted specifically to your degree program and batch.
* **RSVP & Registration:** Register to attend events with a single click and manage personal RSVPs.

### 2. 🗓️ Event Organizer Portal (Clubs & Societies)
* **Event Proposals:** Submit event proposals with titles, descriptions, dates, and times.
* **Multi-Batch Targeting:** Select one or multiple target batches (e.g., Year 1 Computing and Year 2 Networks).
* **Smart Clash-Detection Engine (Core USP):** Automatically cross-references proposed event times against imported academic batch timetables, warning organizers instantly of any lecture conflicts before saving.

### 3. ⚙️ Administrator Portal
* **Facility Management (CRUD):** Manage campus venues (auditoriums, labs, sports grounds) with seating capacities and locations.
* **Timetable CSV Importer:** Upload institutional academic timetable CSV exports; the intelligent parser automatically reads faculties, degree programs, years, semesters, batches, weekdays, and class periods, bulk-inserting them into MySQL.
* **Event Moderation:** Review proposed events and update their status between `draft`, `published`, `cancelled`, or `completed`.

---

## 🛠️ Technology Stack
* **Frontend:** HTML5, CSS3, JavaScript, Bootstrap 5 (Responsive UI)
* **Backend:** PHP (Procedural/Modular with strict typing and security hardening)
* **Database:** MySQL (Relational schema with foreign key constraints and indexes)
* **Server Environment:** Laravel Herd / Apache / Nginx (Local development)
* **Version Control:** Git & GitHub

---

## 📂 Project Structure
```text
nsbm_campus_hub/
├── admin/
│   ├── manage_facilities.php     # Facility CRUD management
│   ├── manage_timetables.php     # Timetable CSV Importer
│   └── manage_events.php         # Event moderation & publishing
├── organizer/
│   └── propose_event.php         # Event proposal & smart clash-detection
├── student/
│   └── browse_events.php         # Event discovery & RSVP portal
├── config/
│   └── db.php                    # Secure PDO database connection
├── assets/
│   ├── css/
│   │   └── style.css             # Custom styling
│   └── js/
│       └── script.js             # Client-side validation & behavior
├── index.php                     # Login & entry point
├── register.php                  # User registration with role selection
├── dashboard.php                 # Role-based protected router
├── logout.php                    # Secure session destruction script
└── database.sql                  # Relational database schema
```

---

## ⚙️ Installation & Local Setup

1. **Clone the Repository:**
   ```bash
   git clone https://github.com/Malith-nethsiri/nsbm-campus-hub.git
   ```

2. **Configure Local Server:**
   Place the project folder inside your local server directory (e.g., Laravel Herd parked folder or XAMPP `htdocs`).

3. **Set Up the Database:**
   * Open your MySQL database manager (phpMyAdmin / MySQL Workbench).
   * Create a new database named **`nsbm_campus_hub`**.
   * Import the **`database.sql`** file provided in the root directory.

4. **Database Connection Configuration:**
   Verify your database credentials in `config/db.php`:
   ```php
   $host = '127.0.0.1';
   $dbname = 'nsbm_campus_hub';
   $username = 'root';
   $password = '';
   ```

5. **Run the Application:**
   Open your browser and navigate to your local domain (e.g., `http://nsbm-campus-hub.test` or `http://localhost/nsbm_campus_hub`).

---

## 🔒 Security Measures
* **Session Hardening:** Configured with `cookie_httponly`, `cookie_samesite => 'Lax'`, and `use_strict_mode`.
* **SQL Injection Protection:** 100% prepared statements using PDO.
* **CSRF Defense:** Unique token validation using `hash_equals()` across all forms and state-changing requests.
* **XSS Neutralization:** Strict output escaping using `htmlspecialchars()`.
* **Password Security:** Salted hashing via `password_hash()` and verification via `password_verify()`.

---

## 👥 Author
* **Developer:** Malith Nethsiri (NSBM Faculty of Computing)
* **Module:** Web and Mobile Application Development (Batch 26.1 IFSL)