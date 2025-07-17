// src/pages/Settings.js - Fixed with immediate language switching

import React, { useState, useEffect } from "react";
import { useTranslation } from "react-i18next";
import TimezoneSelect from "react-timezone-select";
import axios from "axios";
import { useAuth } from "../contexts/AuthContext";

const Settings = () => {
  const { t, i18n } = useTranslation();
  const { user, updateUser } = useAuth();
  const [settings, setSettings] = useState({
    timezone: "UTC",
    language: "en",
    currency: "USD",
  });
  const [currencies, setCurrencies] = useState([]);
  const [saving, setSaving] = useState(false);

  const languages = [
    { code: "en", name: "English" },
    { code: "uk", name: "Українська" },
    { code: "ru", name: "Русский" },
    { code: "de", name: "Deutsch" },
    { code: "es", name: "Español" },
  ];

  useEffect(() => {
    fetchSettings();
  }, []);

  const fetchSettings = async () => {
    try {
      const response = await axios.get(
        `${process.env.REACT_APP_API_URL}/api/user/settings`
      );
      setSettings(response.data.settings);
      setCurrencies(response.data.available_currencies);
    } catch (error) {
      console.error("Failed to fetch settings:", error);
    }
  };

  const handleLanguageChange = (newLanguage) => {
    // Update local state
    setSettings((prev) => ({ ...prev, language: newLanguage }));

    // Immediately change the app language
    i18n.changeLanguage(newLanguage);
    localStorage.setItem("language", newLanguage);

    // Update user context immediately for UI consistency
    updateUser({
      ...user,
      settings: {
        ...user.settings,
        language: newLanguage,
      },
    });
  };

  const handleSubmit = async (e) => {
    e.preventDefault();
    setSaving(true);

    try {
      const response = await axios.post(
        `${process.env.REACT_APP_API_URL}/api/user/settings`,
        settings
      );

      // Update user context with all settings
      updateUser({ ...user, settings: response.data.settings });

      alert(t("settings_saved"));
    } catch (error) {
      console.error("Failed to save settings:", error);
      alert(t("settings_save_error"));
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="max-w-3xl mx-auto">
      <h1 className="text-2xl font-semibold text-gray-900 mb-6">
        {t("settings")}
      </h1>

      <form
        onSubmit={handleSubmit}
        className="space-y-6 bg-white shadow px-6 py-8 rounded-lg"
      >
        {/* Timezone */}
        <div>
          <label className="block text-sm font-medium text-gray-700 mb-2">
            {t("timezone")}
          </label>
          <TimezoneSelect
            value={settings.timezone}
            onChange={(tz) => setSettings({ ...settings, timezone: tz.value })}
          />
          <p className="mt-1 text-sm text-gray-500">
            {t("timezone_description")}
          </p>
        </div>

        {/* Language - with immediate switching */}
        <div>
          <label
            htmlFor="language"
            className="block text-sm font-medium text-gray-700"
          >
            {t("language")}
          </label>
          <select
            id="language"
            value={settings.language}
            onChange={(e) => handleLanguageChange(e.target.value)}
            className="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm"
          >
            {languages.map((lang) => (
              <option key={lang.code} value={lang.code}>
                {lang.name}
              </option>
            ))}
          </select>
          <p className="mt-1 text-sm text-gray-500">
            Language changes immediately for better user experience
          </p>
        </div>

        {/* Currency */}
        <div>
          <label
            htmlFor="currency"
            className="block text-sm font-medium text-gray-700"
          >
            {t("currency")}
          </label>
          <select
            id="currency"
            value={settings.currency}
            onChange={(e) =>
              setSettings({ ...settings, currency: e.target.value })
            }
            className="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm"
          >
            {currencies.map((currency) => (
              <option key={currency.code} value={currency.code}>
                {currency.code} - {currency.name}
              </option>
            ))}
          </select>
          <p className="mt-1 text-sm text-gray-500">
            {t("currency_description")}
          </p>
        </div>

        {/* Submit Button */}
        <div className="flex justify-end">
          <button
            type="submit"
            disabled={saving}
            className="inline-flex justify-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 disabled:opacity-50"
          >
            {saving ? t("saving") : t("save_settings")}
          </button>
        </div>
      </form>

      {/* Account Information */}
      <div className="mt-8 bg-white shadow px-6 py-8 rounded-lg">
        <h2 className="text-lg font-medium text-gray-900 mb-4">
          {t("account_information")}
        </h2>
        <dl className="space-y-4">
          <div>
            <dt className="text-sm font-medium text-gray-500">
              {t("telegram_id")}
            </dt>
            <dd className="text-sm text-gray-900">{user?.telegram_id}</dd>
          </div>
          <div>
            <dt className="text-sm font-medium text-gray-500">
              {t("username")}
            </dt>
            <dd className="text-sm text-gray-900">@{user?.username}</dd>
          </div>
          <div>
            <dt className="text-sm font-medium text-gray-500">
              {t("member_since")}
            </dt>
            <dd className="text-sm text-gray-900">
              {user?.created_at &&
                new Date(user.created_at).toLocaleDateString()}
            </dd>
          </div>
        </dl>
      </div>
    </div>
  );
};

export default Settings;
