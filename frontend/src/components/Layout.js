// src/components/Layout.js - With Language Selector in Header

import React, { useState, useEffect } from "react";
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
  GlobeAltIcon,
  InformationCircleIcon,
} from "@heroicons/react/outline";

// Simple hamburger and close icons using SVG
const HamburgerIcon = ({ className }) => (
  <svg
    className={className}
    fill="none"
    viewBox="0 0 24 24"
    stroke="currentColor"
  >
    <path
      strokeLinecap="round"
      strokeLinejoin="round"
      strokeWidth={2}
      d="M4 6h16M4 12h16M4 18h16"
    />
  </svg>
);

const CloseIcon = ({ className }) => (
  <svg
    className={className}
    fill="none"
    viewBox="0 0 24 24"
    stroke="currentColor"
  >
    <path
      strokeLinecap="round"
      strokeLinejoin="round"
      strokeWidth={2}
      d="M6 18L18 6M6 6l12 12"
    />
  </svg>
);

const Layout = () => {
  const { t, i18n } = useTranslation();
  const { user, logout, updateUser } = useAuth();
  const location = useLocation();
  const navigate = useNavigate();
  const [mobileMenuOpen, setMobileMenuOpen] = useState(false);
  const [languageDropdownOpen, setLanguageDropdownOpen] = useState(false);

  const languages = [
    { code: "en", name: "EN", fullName: "English" },
    { code: "uk", name: "UK", fullName: "Українська" },
    { code: "ru", name: "RU", fullName: "Русский" },
    { code: "de", name: "DE", fullName: "Deutsch" },
    { code: "es", name: "ES", fullName: "Español" },
  ];

  // Close mobile menu when route changes
  useEffect(() => {
    setMobileMenuOpen(false);
  }, [location.pathname]);

  // Close dropdowns when clicking outside
  useEffect(() => {
    const handleClickOutside = (event) => {
      if (mobileMenuOpen && !event.target.closest(".nav-container")) {
        setMobileMenuOpen(false);
      }
      if (languageDropdownOpen && !event.target.closest(".language-dropdown")) {
        setLanguageDropdownOpen(false);
      }
    };

    document.addEventListener("mousedown", handleClickOutside);
    return () => {
      document.removeEventListener("mousedown", handleClickOutside);
    };
  }, [mobileMenuOpen, languageDropdownOpen]);

  const navigation = [
    { name: t("dashboard"), href: "/dashboard", icon: HomeIcon },
    { name: t("groups"), href: "/groups", icon: UserGroupIcon },
    { name: t("scheduled_posts"), href: "/posts", icon: ClockIcon },
    { name: t("calendar"), href: "/calendar", icon: CalendarIcon },
    { name: t("statistics"), href: "/statistics", icon: ChartBarIcon },
    { name: t("subscription"), href: "/subscription", icon: CreditCardIcon },
    { name: t("settings"), href: "/settings", icon: CogIcon },
    { name: "Help", href: "/help", icon: InformationCircleIcon },
  ];

  const handleLogout = () => {
    logout();
    navigate("/login");
    setMobileMenuOpen(false);
  };

  const handleNavClick = () => {
    setMobileMenuOpen(false);
  };

  const changeLanguage = async (langCode) => {
    try {
      // Change language immediately
      i18n.changeLanguage(langCode);
      localStorage.setItem("language", langCode);

      // Update user context
      const updatedUser = {
        ...user,
        settings: {
          ...user.settings,
          language: langCode,
        },
      };
      updateUser(updatedUser);

      // Save to backend
      const response = await fetch(
        `${process.env.REACT_APP_API_URL}/api/user/settings`,
        {
          method: "POST",
          headers: {
            "Content-Type": "application/json",
            Authorization: `Bearer ${localStorage.getItem("token")}`,
          },
          body: JSON.stringify({
            ...user.settings,
            language: langCode,
          }),
        }
      );

      if (!response.ok) {
        console.error("Failed to save language setting");
      }
    } catch (error) {
      console.error("Error changing language:", error);
    }

    setLanguageDropdownOpen(false);
  };

  const getCurrentLanguage = () => {
    const currentLang = i18n.language || user?.settings?.language || "en";
    return languages.find((lang) => lang.code === currentLang) || languages[0];
  };

  return (
    <div className="min-h-screen bg-gray-100">
      {/* Mobile menu overlay */}
      {mobileMenuOpen && (
        <div
          className="fixed inset-0 bg-black bg-opacity-25 z-40 lg:hidden"
          onClick={() => setMobileMenuOpen(false)}
        />
      )}

      <nav className="bg-white shadow-sm relative z-50 nav-container">
        <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
          <div className="flex justify-between items-center h-16">
            {/* Left side - Logo */}
            <div className="flex-shrink-0">
              <h1 className="text-lg lg:text-xl font-bold text-gray-900">
                <span className="hidden sm:inline">Telegram Scheduler</span>
                <span className="sm:hidden">TS</span>
              </h1>
            </div>

            {/* Center - Desktop Navigation */}
            <div className="hidden lg:flex lg:items-center lg:space-x-1 xl:space-x-2">
              {navigation.map((item) => (
                <Link
                  key={item.name}
                  to={item.href}
                  className={`${
                    location.pathname === item.href
                      ? "border-indigo-500 text-gray-900 bg-indigo-50"
                      : "border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700 hover:bg-gray-50"
                  } inline-flex items-center px-2 xl:px-3 py-2 border-b-2 text-sm font-medium transition-colors whitespace-nowrap rounded-t-md`}
                >
                  <item.icon className="w-4 h-4 mr-1 xl:mr-2 flex-shrink-0" />
                  <span className="hidden xl:inline">{item.name}</span>
                  <span className="xl:hidden">{item.name.split(" ")[0]}</span>
                </Link>
              ))}
            </div>

            {/* Right side - Language, User info and Desktop Logout */}
            <div className="hidden lg:flex lg:items-center lg:space-x-2 flex-shrink-0">
              {/* Language Selector */}
              <div className="relative language-dropdown">
                <button
                  onClick={() => setLanguageDropdownOpen(!languageDropdownOpen)}
                  className="inline-flex items-center px-2 py-1 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
                >
                  <GlobeAltIcon className="w-4 h-4 mr-1" />
                  {getCurrentLanguage().name}
                </button>

                {languageDropdownOpen && (
                  <div className="absolute right-0 mt-2 w-40 bg-white rounded-md shadow-lg py-1 z-50 border border-gray-200">
                    {languages.map((lang) => (
                      <button
                        key={lang.code}
                        onClick={() => changeLanguage(lang.code)}
                        className={`${
                          getCurrentLanguage().code === lang.code
                            ? "bg-indigo-50 text-indigo-700"
                            : "text-gray-700 hover:bg-gray-100"
                        } block w-full text-left px-4 py-2 text-sm`}
                      >
                        {lang.fullName}
                      </button>
                    ))}
                  </div>
                )}
              </div>

              {/* User Info */}
              <div className="flex items-center text-sm max-w-xs">
                <div className="flex flex-col items-end mr-2 min-w-0">
                  <span className="font-medium text-gray-900 text-sm truncate max-w-[120px] xl:max-w-full">
                    {user?.first_name} {user?.last_name}
                  </span>
                  {user?.username && (
                    <span className="text-xs text-gray-500 truncate max-w-[120px] xl:max-w-full">
                      @{user.username}
                    </span>
                  )}
                </div>
                {user?.subscription && (
                  <span
                    className={`text-xs px-2 py-1 rounded flex-shrink-0 ${
                      user.subscription.plan === "free"
                        ? "bg-gray-100 text-gray-600"
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
                className="inline-flex items-center p-2 text-gray-400 hover:text-gray-500 hover:bg-gray-100 rounded-md transition-colors flex-shrink-0"
                title={t("logout")}
              >
                <LogoutIcon className="w-5 h-5" />
              </button>
            </div>

            {/* Mobile menu button */}
            <div className="lg:hidden flex items-center space-x-2">
              {/* Mobile Language Selector */}
              <div className="relative language-dropdown">
                <button
                  onClick={() => setLanguageDropdownOpen(!languageDropdownOpen)}
                  className="inline-flex items-center p-2 text-gray-400 hover:text-gray-500 hover:bg-gray-100 rounded-md transition-colors"
                >
                  <GlobeAltIcon className="w-5 h-5" />
                </button>

                {languageDropdownOpen && (
                  <div className="absolute right-0 mt-2 w-36 bg-white rounded-md shadow-lg py-1 z-50 border border-gray-200">
                    {languages.map((lang) => (
                      <button
                        key={lang.code}
                        onClick={() => changeLanguage(lang.code)}
                        className={`${
                          getCurrentLanguage().code === lang.code
                            ? "bg-indigo-50 text-indigo-700"
                            : "text-gray-700 hover:bg-gray-100"
                        } block w-full text-left px-4 py-2 text-sm`}
                      >
                        {lang.fullName}
                      </button>
                    ))}
                  </div>
                )}
              </div>

              {/* Hamburger Menu Button */}
              <button
                onClick={() => setMobileMenuOpen(!mobileMenuOpen)}
                className="inline-flex items-center justify-center p-2 rounded-md text-gray-400 hover:text-gray-500 hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-indigo-500 transition-colors"
                aria-expanded={mobileMenuOpen}
                aria-label="Toggle navigation menu"
              >
                {mobileMenuOpen ? (
                  <CloseIcon className="block h-6 w-6" aria-hidden="true" />
                ) : (
                  <HamburgerIcon className="block h-6 w-6" aria-hidden="true" />
                )}
              </button>
            </div>
          </div>
        </div>

        {/* Mobile menu */}
        {mobileMenuOpen && (
          <div className="lg:hidden absolute top-full left-0 right-0 z-45 shadow-lg">
            <div className="px-2 pt-2 pb-3 space-y-1 sm:px-3 bg-white border-t border-gray-200">
              {/* User info section for mobile */}
              <div className="px-3 py-3 border-b border-gray-200 mb-2">
                <div className="flex items-center justify-between">
                  <div className="flex-1">
                    <div className="text-base font-medium text-gray-800">
                      {user?.first_name} {user?.last_name}
                    </div>
                    {user?.username && (
                      <div className="text-sm text-gray-500">
                        @{user.username}
                      </div>
                    )}
                  </div>
                  {user?.subscription && (
                    <span
                      className={`text-xs px-2 py-1 rounded ${
                        user.subscription.plan === "free"
                          ? "bg-gray-100 text-gray-600"
                          : user.subscription.plan === "pro"
                          ? "bg-blue-100 text-blue-800"
                          : "bg-purple-100 text-purple-800"
                      }`}
                    >
                      {user.subscription.plan.toUpperCase()}
                    </span>
                  )}
                </div>
              </div>

              {/* Navigation links for mobile */}
              {navigation.map((item) => (
                <Link
                  key={item.name}
                  to={item.href}
                  onClick={handleNavClick}
                  className={`${
                    location.pathname === item.href
                      ? "bg-indigo-50 border-indigo-500 text-indigo-700"
                      : "border-transparent text-gray-600 hover:bg-gray-50 hover:border-gray-300 hover:text-gray-800"
                  } block pl-3 pr-4 py-3 border-l-4 text-base font-medium transition-colors`}
                >
                  <div className="flex items-center">
                    <item.icon className="w-5 h-5 mr-3" />
                    {item.name}
                  </div>
                </Link>
              ))}

              {/* Logout button for mobile */}
              <button
                onClick={handleLogout}
                className="w-full text-left block pl-3 pr-4 py-3 border-l-4 border-transparent text-base font-medium text-gray-600 hover:bg-gray-50 hover:border-gray-300 hover:text-gray-800 transition-colors"
              >
                <div className="flex items-center">
                  <LogoutIcon className="w-5 h-5 mr-3" />
                  {t("logout")}
                </div>
              </button>
            </div>
          </div>
        )}
      </nav>

      <main className="max-w-7xl mx-auto py-6 sm:px-6 lg:px-8">
        <div className="px-4 sm:px-0">
          <Outlet />
        </div>
      </main>
    </div>
  );
};

export default Layout;
