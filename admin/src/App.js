import { SlotFillProvider } from '@wordpress/components';
import TestModeBanner from './components/TestModeBanner';
import Campaigns from './pages/Campaigns';
import Dashboard from './pages/Dashboard';
import Donors from './pages/Donors';
import Fundraisers from './pages/Fundraisers';
import Settings from './pages/Settings';
import Subscriptions from './pages/Subscriptions';
import Teams from './pages/Teams';
import Tools from './pages/Tools';
import Transactions from './pages/Transactions';

const pages = {
  campaigns: Campaigns,
  dashboard: Dashboard,
  donors: Donors,
  fundraisers: Fundraisers,
  settings: Settings,
  subscriptions: Subscriptions,
  teams: Teams,
  tools: Tools,
  transactions: Transactions,
};

export default function App( { container } ) {
  const page = container.getAttribute( 'data-page' ) || 'dashboard';
  const PageComponent = pages[ page ] || pages.dashboard;

  return (
    <SlotFillProvider>
      <TestModeBanner />
      <PageComponent />
    </SlotFillProvider>
  );
}
