<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FSS OYO Polytechnic - Computer Science CBT Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --color-text: #0a0f1f;
            --color-text-secondary: #64748b;
            --color-text-muted: #94a3b8;
            --color-border: #e2e8f0;
            --color-border-light: #f1f5f9;
            --color-bg: #ffffff;
            --color-bg-secondary: #f8fafc;
            --color-bg-tertiary: #f1f5f9;
            --color-primary: #0a0f1f;
            --color-primary-hover: #1e293b;
            --color-success: #10b981;
            --color-warning: #f59e0b;
            --color-danger: #ef4444;
            --color-info: #3b82f6;
            --radius-sm: 6px;
            --radius-md: 10px;
            --radius-lg: 14px;
            --radius-xl: 18px;
            --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.03);
            --shadow-md: 0 1px 3px 0 rgba(0, 0, 0, 0.04), 0 1px 2px -1px rgba(0, 0, 0, 0.04);
            --shadow-lg: 0 4px 6px -1px rgba(0, 0, 0, 0.04), 0 2px 4px -2px rgba(0, 0, 0, 0.04);
        }

        * {
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background-color: var(--color-bg);
            color: var(--color-text);
            line-height: 1.6;
        }

        h1, h2, h3, h4, h5, h6 {
            font-family: 'Inter', sans-serif;
            font-weight: 700;
            color: var(--color-text);
            letter-spacing: -0.02em;
        }

        h1 { font-size: 2.75rem; line-height: 1.15; font-weight: 800; }
        h2 { font-size: 2rem; line-height: 1.2; font-weight: 700; }
        h3 { font-size: 1.5rem; line-height: 1.3; font-weight: 700; }
        h4 { font-size: 1.25rem; line-height: 1.4; font-weight: 600; }
        h5 { font-size: 1.1rem; line-height: 1.4; font-weight: 600; }
        h6 { font-size: 1rem; line-height: 1.5; font-weight: 600; }

        p.lead {
            font-size: 1.125rem;
            line-height: 1.7;
            color: var(--color-text-secondary);
        }

        .text-muted {
            color: var(--color-text-muted) !important;
        }

        .btn {
            font-weight: 500;
            font-size: 0.9rem;
            padding: 0.65rem 1.25rem;
            border-radius: var(--radius-md);
            transition: all 0.15s ease;
            letter-spacing: -0.01em;
        }

        .btn-lg {
            padding: 0.9rem 1.75rem;
            font-size: 1rem;
        }

        .btn-sm {
            padding: 0.4rem 0.8rem;
            font-size: 0.8rem;
        }

        .btn-primary {
            background-color: var(--color-primary);
            border-color: var(--color-primary);
            color: #fff;
        }

        .btn-primary:hover {
            background-color: var(--color-primary-hover);
            border-color: var(--color-primary-hover);
            color: #fff;
            transform: translateY(-1px);
        }

        .btn-outline-primary {
            border-color: var(--color-border);
            color: var(--color-text);
            background-color: transparent;
        }

        .btn-outline-primary:hover {
            background-color: var(--color-bg-tertiary);
            border-color: var(--color-border);
            color: var(--color-text);
        }

        .btn-success {
            background-color: var(--color-success);
            border-color: var(--color-success);
        }

        .btn-warning {
            background-color: var(--color-warning);
            border-color: var(--color-warning);
            color: #000;
        }

        .btn-danger {
            background-color: var(--color-danger);
            border-color: var(--color-danger);
        }

        .btn-info {
            background-color: var(--color-info);
            border-color: var(--color-info);
        }

        .card {
            border: 1px solid var(--color-border);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-sm);
            background-color: var(--color-bg);
        }

        .card-header {
            background-color: var(--color-bg);
            border-bottom: 1px solid var(--color-border);
            padding: 1.25rem 1.5rem;
            font-weight: 600;
            border-radius: var(--radius-lg) var(--radius-lg) 0 0 !important;
        }

        .card-body {
            padding: 1.5rem;
        }

        .badge {
            font-weight: 500;
            font-size: 0.75rem;
            padding: 0.35em 0.75em;
            border-radius: var(--radius-sm);
            letter-spacing: 0.01em;
        }

        .bg-primary { background-color: var(--color-primary) !important; }
        .bg-success { background-color: #ecfdf5 !important; color: #059669 !important; }
        .bg-info { background-color: #eff6ff !important; color: #2563eb !important; }
        .bg-warning { background-color: #fffbeb !important; color: #d97706 !important; }
        .bg-danger { background-color: #fef2f2 !important; color: #dc2626 !important; }
        .bg-secondary { background-color: var(--color-bg-tertiary) !important; color: var(--color-text-secondary) !important; }

        .form-control, .form-select {
            padding: 0.7rem 1rem;
            border-radius: var(--radius-md);
            border: 1px solid var(--color-border);
            font-size: 0.95rem;
            transition: all 0.15s ease;
        }

        .form-control:focus, .form-select:focus {
            border-color: var(--color-primary);
            box-shadow: 0 0 0 3px rgba(10, 15, 31, 0.06);
        }

        .form-label {
            font-weight: 500;
            color: var(--color-text);
            font-size: 0.9rem;
            margin-bottom: 0.5rem;
        }

        .input-group-text {
            background-color: var(--color-bg-secondary);
            border: 1px solid var(--color-border);
            border-radius: var(--radius-md);
            color: var(--color-text-secondary);
        }

        .alert {
            border-radius: var(--radius-md);
            border: 1px solid;
            padding: 0.9rem 1.1rem;
            font-size: 0.9rem;
        }

        .alert-success {
            background-color: #ecfdf5;
            border-color: #d1fae5;
            color: #065f46;
        }

        .alert-danger {
            background-color: #fef2f2;
            border-color: #fecaca;
            color: #991b1b;
        }

        .alert-warning {
            background-color: #fffbeb;
            border-color: #fde68a;
            color: #92400e;
        }

        .alert-info {
            background-color: #eff6ff;
            border-color: #bfdbfe;
            color: #1e40af;
        }

        .table {
            font-size: 0.9rem;
        }

        .table > :not(caption) > * > * {
            padding: 0.9rem 1rem;
            border-bottom-width: 1px;
            border-bottom-color: var(--color-border-light);
        }

        .table thead th {
            background-color: var(--color-bg-secondary);
            color: var(--color-text-secondary);
            font-weight: 600;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            border-bottom: 1px solid var(--color-border);
        }

        .table tbody tr:hover {
            background-color: var(--color-bg-secondary);
        }

        .navbar {
            padding: 1rem 0;
            border-bottom: 1px solid var(--color-border);
            background-color: var(--color-bg);
        }

        .navbar-brand {
            font-weight: 700;
            font-size: 1.15rem;
            color: var(--color-text) !important;
            letter-spacing: -0.02em;
        }

        .nav-link {
            font-size: 0.9rem;
            font-weight: 500;
            color: var(--color-text-secondary);
            transition: color 0.15s ease;
        }

        .nav-link:hover, .nav-link.active {
            color: var(--color-text) !important;
        }

        .list-group-item {
            border-color: var(--color-border-light);
            padding: 1rem 1.25rem;
            font-size: 0.95rem;
        }

        .list-group-item:first-child {
            border-top-left-radius: var(--radius-md);
            border-top-right-radius: var(--radius-md);
        }

        .list-group-item:last-child {
            border-bottom-left-radius: var(--radius-md);
            border-bottom-right-radius: var(--radius-md);
        }

        .dropdown-menu {
            border-radius: var(--radius-md);
            border: 1px solid var(--color-border);
            box-shadow: var(--shadow-lg);
            padding: 0.5rem;
        }

        .dropdown-item {
            border-radius: var(--radius-sm);
            padding: 0.55rem 0.85rem;
            font-size: 0.9rem;
        }

        .dropdown-item:hover {
            background-color: var(--color-bg-secondary);
        }

        .page-header {
            margin-bottom: 2rem;
        }

        .stat-card {
            border: 1px solid var(--color-border);
            border-radius: var(--radius-lg);
            padding: 1.5rem;
            background: var(--color-bg);
            box-shadow: var(--shadow-sm);
            transition: all 0.2s ease;
        }

        .stat-card:hover {
            box-shadow: var(--shadow-md);
            transform: translateY(-2px);
        }

        .stat-card .stat-label {
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--color-text-secondary);
            font-weight: 600;
            margin-bottom: 0.5rem;
        }

        .stat-card .stat-value {
            font-size: 2.25rem;
            font-weight: 800;
            letter-spacing: -0.03em;
            color: var(--color-text);
            line-height: 1;
            margin-bottom: 0.35rem;
        }

        .stat-card .stat-icon {
            width: 42px;
            height: 42px;
            border-radius: var(--radius-md);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.15rem;
            margin-bottom: 1rem;
        }

        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            bottom: 0;
            width: 260px;
            background: var(--color-bg);
            border-right: 1px solid var(--color-border);
            padding: 1.5rem 1rem;
            z-index: 1000;
            overflow-y: auto;
        }

        .sidebar-brand {
            display: flex;
            align-items: center;
            padding: 0.5rem 0.75rem 1.5rem;
            border-bottom: 1px solid var(--color-border-light);
            margin-bottom: 1rem;
        }

        .sidebar-brand img {
            height: 38px;
            width: 38px;
            border-radius: var(--radius-sm);
            object-fit: cover;
            margin-right: 0.75rem;
        }

        .sidebar-brand h5 {
            margin: 0;
            font-size: 1rem;
            line-height: 1.2;
        }

        .sidebar-brand span {
            font-size: 0.7rem;
            color: var(--color-text-muted);
            font-weight: 500;
        }

        .sidebar-nav {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
            margin-bottom: 2rem;
        }

        .sidebar-section-title {
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: var(--color-text-muted);
            font-weight: 700;
            padding: 0.85rem 0.75rem 0.4rem;
        }

        .sidebar-link {
            display: flex;
            align-items: center;
            padding: 0.7rem 0.85rem;
            border-radius: var(--radius-md);
            color: var(--color-text-secondary);
            text-decoration: none;
            font-size: 0.9rem;
            font-weight: 500;
            transition: all 0.15s ease;
        }

        .sidebar-link:hover {
            background-color: var(--color-bg-secondary);
            color: var(--color-text);
            text-decoration: none;
        }

        .sidebar-link.active {
            background-color: var(--color-bg-tertiary);
            color: var(--color-text);
            font-weight: 600;
        }

        .sidebar-link i {
            width: 20px;
            margin-right: 0.75rem;
            font-size: 0.95rem;
            text-align: center;
        }

        .sidebar-user {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            padding: 1rem;
            border-top: 1px solid var(--color-border-light);
            background: var(--color-bg);
        }

        .main-content {
            margin-left: 260px;
            min-height: 100vh;
            background-color: var(--color-bg-secondary);
        }

        .top-bar {
            background: var(--color-bg);
            border-bottom: 1px solid var(--color-border);
            padding: 1rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .page-content {
            padding: 2rem;
        }

        .feature-card {
            border: 1px solid var(--color-border);
            border-radius: var(--radius-lg);
            padding: 2rem;
            background: var(--color-bg);
            transition: all 0.2s ease;
            height: 100%;
        }

        .feature-card:hover {
            box-shadow: var(--shadow-md);
            transform: translateY(-3px);
        }

        .feature-icon {
            width: 48px;
            height: 48px;
            border-radius: var(--radius-md);
            background-color: var(--color-bg-tertiary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.35rem;
            color: var(--color-primary);
            margin-bottom: 1.25rem;
        }

        .hero-section {
            padding: 5rem 0;
        }

        .section {
            padding: 5rem 0;
        }

        .section-alt {
            background-color: var(--color-bg-secondary);
        }

        .showcase-image {
            border-radius: var(--radius-xl);
            border: 1px solid var(--color-border);
            box-shadow: var(--shadow-lg);
            overflow: hidden;
            aspect-ratio: 16/10;
        }

        .showcase-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .category-nav {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
        }

        .category-nav a {
            padding: 0.55rem 1rem;
            border: 1px solid var(--color-border);
            border-radius: 100px;
            font-size: 0.85rem;
            color: var(--color-text-secondary);
            text-decoration: none;
            transition: all 0.15s ease;
            font-weight: 500;
        }

        .category-nav a:hover, .category-nav a.active {
            background-color: var(--color-primary);
            border-color: var(--color-primary);
            color: #fff;
        }

        .greeting-text h2 {
            margin-bottom: 0.35rem;
        }

        .greeting-text p {
            color: var(--color-text-secondary);
            margin: 0;
        }

        .quick-action-btn {
            display: flex;
            align-items: center;
            padding: 1rem 1.25rem;
            border: 1px solid var(--color-border);
            border-radius: var(--radius-md);
            background: var(--color-bg);
            color: var(--color-text);
            text-decoration: none;
            transition: all 0.15s ease;
            font-weight: 500;
        }

        .quick-action-btn:hover {
            background-color: var(--color-bg-secondary);
            border-color: var(--color-primary);
            transform: translateY(-2px);
            color: var(--color-text);
            text-decoration: none;
        }

        .quick-action-btn i {
            width: 36px;
            height: 36px;
            border-radius: var(--radius-sm);
            background-color: var(--color-bg-tertiary);
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 0.85rem;
            font-size: 1rem;
        }

        .mobile-nav-toggle {
            display: none;
        }

        @media (max-width: 992px) {
            h1 { font-size: 2.25rem; }
            h2 { font-size: 1.75rem; }

            .sidebar {
                transform: translateX(-100%);
                transition: transform 0.25s ease;
            }

            .sidebar.open {
                transform: translateX(0);
            }

            .main-content {
                margin-left: 0;
            }

            .mobile-nav-toggle {
                display: inline-flex;
            }

            .page-content {
                padding: 1.25rem;
            }

            .top-bar {
                padding: 0.85rem 1.25rem;
            }

            .hero-section, .section {
                padding: 3rem 0;
            }
        }

        @media (max-width: 576px) {
            h1 { font-size: 1.85rem; }
            h2 { font-size: 1.5rem; }

            .stat-card .stat-value {
                font-size: 1.75rem;
            }
        }

        .image-fallback {
            background: linear-gradient(135deg, var(--color-bg-tertiary) 0%, var(--color-bg-secondary) 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--color-text-muted);
            font-size: 1rem;
        }

        .backdrop-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.35);
            z-index: 999;
        }

        .backdrop-overlay.show {
            display: block;
        }

        .table-responsive {
            border-radius: var(--radius-lg);
            border: 1px solid var(--color-border);
        }
    </style>
</head>
<body>
