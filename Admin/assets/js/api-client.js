/**
 * API Client for Rental Management System
 * 
 * This file provides helper functions for making authenticated API requests
 */

const APIClient = {
    /**
     * Base URL for API endpoints
     */
    baseUrl: '/RMS/Admin/api',

    /**
     * Make an authenticated API request
     * 
     * @param {string} endpoint - The API endpoint to call
     * @param {Object} options - Request options
     * @param {string} options.method - HTTP method (GET, POST, PUT, DELETE)
     * @param {Object} options.body - Request body for POST/PUT requests
     * @param {boolean} options.includeToken - Whether to include the JWT token
     * @returns {Promise<Object>} The API response
     */
    async request(endpoint, options = {}) {
        const { 
            method = 'GET', 
            body = null, 
            includeToken = true 
        } = options;

        // Prepare headers
        const headers = {
            'Content-Type': 'application/json',
            'Accept': 'application/json'
        };

        // Include JWT token if required
        // The token is automatically included from the httpOnly cookie
        // We don't need to explicitly add it to the headers

        // Prepare request options
        const requestOptions = {
            method,
            headers,
            credentials: 'include' // Include cookies
        };

        // Add request body for POST/PUT requests
        if (body && (method === 'POST' || method === 'PUT')) {
            requestOptions.body = JSON.stringify(body);
        }

        // Make the request
        try {
            const response = await fetch(`${this.baseUrl}/${endpoint}`, requestOptions);
            
            // Parse response as JSON
            const data = await response.json();
            
            // Handle API errors
            if (!response.ok) {
                // Create error object with API error message
                const error = new Error(data.message || 'API request failed');
                error.status = response.status;
                error.data = data;
                throw error;
            }
            
            return data;
        } catch (error) {
            // Log error to console
            console.error('API request error:', error);
            throw error;
        }
    },

    /**
     * Get user information
     * 
     * @returns {Promise<Object>} User information
     */
    async getUserInfo() {
        return this.request('user-info.php');
    },

    /**
     * Get admin-only data
     * 
     * @returns {Promise<Object>} Admin-only data
     */
    async getAdminData() {
        return this.request('admin-only.php');
    }
};

// Example usage:
// document.addEventListener('DOMContentLoaded', async () => {
//     try {
//         // Get user information
//         const userData = await APIClient.getUserInfo();
//         console.log('User data:', userData);
//         
//         // Try to access admin area (will fail for non-admin users)
//         if (userData.user.role_id === 1) {
//             const adminData = await APIClient.getAdminData();
//             console.log('Admin data:', adminData);
//         }
//     } catch (error) {
//         console.error('Error:', error);
//     }
// }); 