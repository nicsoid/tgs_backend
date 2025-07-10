// src/pages/Login.js
import React, { useEffect, useState } from "react";
import { useNavigate } from "react-router-dom";
import { useAuth } from "../contexts/AuthContext";
import axios from "axios";

const Login = () => {
  const navigate = useNavigate();
  const { login, user } = useAuth();
  const [error, setError] = useState(null);
  const [loading, setLoading] = useState(false);

  useEffect(() => {
    if (user) {
      navigate("/dashboard");
    }
  }, [user, navigate]);

  useEffect(() => {
    // Create a unique function name for this instance
    const authFunctionName = `onTelegramAuth_${Date.now()}`;

    // Telegram Login Widget Script
    const script = document.createElement("script");
    script.src = "https://telegram.org/js/telegram-widget.js?22";
    script.setAttribute(
      "data-telegram-login",
      process.env.REACT_APP_TELEGRAM_BOT_USERNAME
    );
    script.setAttribute("data-size", "large");
    script.setAttribute("data-onauth", `${authFunctionName}(user)`);
    script.setAttribute("data-request-access", "write");
    script.async = true;

    // Add script to container
    const container = document.getElementById("telegram-login-container");
    if (container) {
      container.appendChild(script);
    }

    // Define the auth callback
    window[authFunctionName] = async (telegramUser) => {
      console.log("Telegram auth data received:", telegramUser);
      setLoading(true);
      setError(null);

      try {
        const response = await axios.post(
          `${process.env.REACT_APP_API_URL}/api/auth/telegram`,
          telegramUser,
          {
            headers: {
              "Content-Type": "application/json",
            },
          }
        );

        console.log("Auth response:", response.data);
        login(response.data.token, response.data.user);
        navigate("/dashboard");
      } catch (error) {
        console.error("Login failed:", error);
        console.error("Error response:", error.response?.data);
        setError(
          error.response?.data?.error || "Login failed. Please try again."
        );
        setLoading(false);
      }
    };

    // Cleanup
    return () => {
      delete window[authFunctionName];
      if (container) {
        container.innerHTML = "";
      }
    };
  }, [login, navigate]);

  // Also check for Telegram Web App
  useEffect(() => {
    if (window.Telegram?.WebApp?.initData) {
      console.log("Telegram Web App detected");
      // Handle Telegram Web App authentication if needed
    }
  }, []);

  return (
    <div className="min-h-screen flex items-center justify-center bg-gray-50">
      <div className="max-w-md w-full space-y-8">
        <div>
          <h2 className="mt-6 text-center text-3xl font-extrabold text-gray-900">
            Telegram Post Scheduler
          </h2>
          <p className="mt-2 text-center text-sm text-gray-600">
            Sign in with your Telegram account to continue
          </p>
        </div>

        {error && (
          <div className="rounded-md bg-red-50 p-4">
            <div className="flex">
              <div className="ml-3">
                <h3 className="text-sm font-medium text-red-800">
                  Authentication Error
                </h3>
                <div className="mt-2 text-sm text-red-700">
                  <p>{error}</p>
                </div>
              </div>
            </div>
          </div>
        )}

        <div className="mt-8 space-y-6">
          <div id="telegram-login-container" className="flex justify-center">
            {loading && (
              <div className="text-center">
                <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-indigo-600 mx-auto"></div>
                <p className="mt-2 text-sm text-gray-600">Authenticating...</p>
              </div>
            )}
          </div>
        </div>

        <div className="mt-8 text-center text-sm text-gray-600">
          <p>
            By signing in, you agree to let this bot send messages on your
            behalf.
          </p>
          <p className="mt-2">
            Bot username:{" "}
            <code className="bg-gray-100 px-1 py-0.5 rounded">
              @{process.env.REACT_APP_TELEGRAM_BOT_USERNAME}
            </code>
          </p>
        </div>
      </div>
    </div>
  );
};

export default Login;
