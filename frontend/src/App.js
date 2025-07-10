// src/App.js

import React from "react";
import {
  BrowserRouter as Router,
  Routes,
  Route,
  Navigate,
} from "react-router-dom";
import { AuthProvider } from "./contexts/AuthContext";
import PrivateRoute from "./components/PrivateRoute";
import Layout from "./components/Layout";
import Login from "./pages/Login";
import Dashboard from "./pages/Dashboard";
import Groups from "./pages/Groups";
import ScheduledPosts from "./pages/ScheduledPosts";
import CreatePost from "./pages/CreatePost";
import Statistics from "./pages/Statistics";
import Calendar from "./pages/Calendar";
import Settings from "./pages/Settings";
import Subscription from "./pages/Subscription";
import "./i18n";

function App() {
  return (
    <AuthProvider>
      <Router>
        <Routes>
          <Route path="/login" element={<Login />} />
          <Route
            path="/"
            element={
              <PrivateRoute>
                <Layout />
              </PrivateRoute>
            }
          >
            <Route index element={<Navigate to="/dashboard" />} />
            <Route path="dashboard" element={<Dashboard />} />
            <Route path="groups" element={<Groups />} />
            <Route path="posts" element={<ScheduledPosts />} />
            <Route path="posts/create" element={<CreatePost />} />
            <Route path="statistics" element={<Statistics />} />
            <Route path="calendar" element={<Calendar />} />
            <Route path="settings" element={<Settings />} />
            <Route path="subscription" element={<Subscription />} />
          </Route>
        </Routes>
      </Router>
    </AuthProvider>
  );
}

export default App;
