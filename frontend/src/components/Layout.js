// src/components/Layout.js

import React from "react";
import { Link, Outlet, useLocation, useNavigate } from "react-router-dom";
import { useTranslation } from "react-i18next";
import { useAuth } from "../contexts/AuthContext";
import {
  HomeIcon,
  UserGroupIcon,
  CalendarIcon,
  ChartBarIcon,
  CogIcon,
  LogoutIcon,
  ClockIcon,
  CreditCardIcon,
} from "@heroicons/react/outline";

const Layout = () => {
  const { t } = useTranslation();
  const { user, logout } = useAuth();
  const location = useLocation();
  const navigate = useNavigate();

  const navigation = [
    { name: t("dashboard"), href: "/dashboard", icon: HomeIcon },
    { name: t("groups"), href: "/groups", icon: UserGroupIcon },
    { name: t("scheduled_posts"), href: "/posts", icon: ClockIcon },
    { name: t("calendar"), href: "/calendar", icon: CalendarIcon },
    { name: t("statistics"), href: "/statistics", icon: ChartBarIcon },
    { name: t("subscription"), href: "/subscription", icon: CreditCardIcon },
    { name: t("settings"), href: "/settings", icon: CogIcon },
  ];

  const handleLogout = () => {
    logout();
    navigate("/login");
  };

  return (
    <div className="min-h-screen bg-gray-100">
      <nav className="bg-white shadow-sm">
        <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
          <div className="flex justify-between h-16">
            <div className="flex">
              <div className="flex-shrink-0 flex items-center">
                <h1 className="text-xl font-bold text-gray-900">
                  Telegram Scheduler
                </h1>
              </div>
              <div className="hidden sm:ml-6 sm:flex sm:space-x-8">
                {navigation.map((item) => (
                  <Link
                    key={item.name}
                    to={item.href}
                    className={`${
                      location.pathname === item.href
                        ? "border-indigo-500 text-gray-900"
                        : "border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700"
                    } inline-flex items-center px-1 pt-1 border-b-2 text-sm font-medium`}
                  >
                    <item.icon className="w-5 h-5 mr-2" />
                    {item.name}
                  </Link>
                ))}
              </div>
            </div>
            <div className="flex items-center">
              <div className="flex items-center text-sm text-gray-500 mr-4">
                <span>
                  {user?.first_name} {user?.last_name}
                </span>
                {user?.subscription && (
                  <span
                    className={`ml-2 text-xs px-2 py-1 rounded ${
                      user.subscription.plan === "free"
                        ? "bg-gray-100"
                        : user.subscription.plan === "pro"
                        ? "bg-blue-100 text-blue-800"
                        : "bg-purple-100 text-purple-800"
                    }`}
                  >
                    {user.subscription.plan.toUpperCase()}
                  </span>
                )}
              </div>
              <button
                onClick={handleLogout}
                className="inline-flex items-center p-2 text-gray-400 hover:text-gray-500"
              >
                <LogoutIcon className="w-5 h-5" />
              </button>
            </div>
          </div>
        </div>
      </nav>

      <main className="max-w-7xl mx-auto py-6 sm:px-6 lg:px-8">
        <Outlet />
      </main>
    </div>
  );
};

export default Layout;
