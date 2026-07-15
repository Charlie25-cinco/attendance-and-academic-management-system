# Project Structure

my-classic-application/
├── admin/                          # Isolated module for administrative management
│   ├── includes/                   # Admin-specific UI snippets
│   ├── index.php                   # Admin control panel home
│   └── manage-users.php            # Administrative operational script
├── assets/                         # Publicly accessible static dependencies
│   ├── css/                        # Compiled or raw Cascading Style Sheets (e.g., main.css)
│   ├── js/                         # Vanilla JS logic or legacy libraries (e.g., jquery.min.js)
│   └── images/                     # User uploads, avatars, icons, and layout graphics
├── config/                         # Core execution settings
│   ├── db.php                      # Database connection bootstrapping (PDO / MySQLi instances)
│   └── constants.php               # Application global strings (URLs, time zones, encryption vectors)
├── functions/                      # Modular functional programming libraries
│   ├── auth-helper.php             # Core user session validations and login checks
│   └── db-helper.php               # Custom query abstraction abstractions
├── includes/                       # Modular page fragments (The DRY Pattern)
│   ├── header.php                  # Global metadata, stylesheets, and navigation bars
│   ├── footer.php                  # Closing HTML elements, scripts, and copyright text
│   └── sidebar.php                 # Core contextual utility layouts
├── templates/                      # Pure HTML structural views (if using engines like Twig/Smarty)
├── .htaccess                       # Apache configuration for deep link rewrites & server security
├── index.php                       # Primary structural interface (The Application Homepage)
├── login.php                       # Explicit page-based endpoint for user authentication
├── register.php                    # Explicit page-based endpoint for profile creation
└── README.md                       # Setup scripts, database installation steps, and schema imports
