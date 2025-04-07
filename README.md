# CharterHub API Backend

This repository contains the API backend for CharterHub, a yacht charter booking and management system.

## Features

- JWT-based authentication with refresh tokens
- User management (admin, client, customer)
- Yacht and booking management
- Document upload/download functionality
- Invitation system for new clients
- Docker support for easy deployment

## Technology Stack

- PHP 8.0+
- MySQL database
- JWT authentication
- Docker containerization

## Project Structure

```
├── api/              # API endpoints
│   ├── admin/        # Admin-only endpoints
│   ├── auth/         # Authentication endpoints
│   ├── client/       # Client-specific endpoints
│   └── public/       # Public endpoints
├── auth/             # Authentication services and utilities
├── config/           # Configuration files and database setup
├── includes/         # Shared functionality
├── uploads/          # File storage directory
├── utils/            # Utility functions
├── vendor/           # Dependencies
├── .dockerignore     # Docker build exclusions
├── .env.example      # Example environment variables
├── Dockerfile        # Docker container definition
├── composer.json     # PHP dependencies
├── docker-compose.yml # Local Docker configuration
├── index.php         # Main entry point
├── render.yaml       # Render deployment configuration
└── vercel.json       # Vercel deployment configuration
```

## Setup

1. Clone this repository
2. Copy `.env.example` to `.env` and configure your environment variables
3. Install dependencies: `composer install`
4. Ensure the `uploads` directory is writable

## Docker Deployment

To run with Docker:

```bash
docker-compose up -d
```

This will start the API server on port 8080.

## Deployment on Render

This repository includes a `render.yaml` file for deployment on [Render](https://render.com). The configuration uses Docker to build and deploy the API.

## API Documentation

### Authentication

- `POST /api/auth/login` - Authenticate a user
- `POST /api/auth/register` - Register a new user
- `POST /api/auth/refresh` - Refresh an access token
- `GET /api/auth/me` - Get current user info

### User Management

- `GET /api/admin/users` - List all users (admin only)
- `POST /api/admin/users/create` - Create a new user (admin only)
- `GET /api/users/profile` - Get current user profile
- `PUT /api/users/profile` - Update user profile

### Booking Management

- `GET /api/client/bookings` - List client bookings
- `POST /api/client/bookings` - Create a new booking

## License

Proprietary software. All rights reserved. 